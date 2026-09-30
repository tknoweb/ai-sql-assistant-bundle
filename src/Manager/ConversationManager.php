<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractConversation;

/**
 * Stores the conversations of the assistant in the entities set in the "entities" configuration of the bundle: AssistantManager runs each turn from the stored history, this manager keeps the
 * history and the cost up to date and logs every turn as an exchange.
 * A turn of the chat is first saved as pending, then run by a request of its own that holds a lock on it until its end: the user may leave the page meanwhile, and finds the turn running,
 * ended or failed when coming back.
 */
class ConversationManager
{
    // States of the pending turn of a conversation (getTurnState())
    public const TURN_WAITING = 'waiting';
    public const TURN_RUNNING = 'running';
    public const TURN_FAILED = 'failed';
    public const TURN_IN_PROGRESS_STATES = [self::TURN_WAITING, self::TURN_RUNNING];

    private const TURN_LOCK_PREFIX = 'tknoweb_ai_sql_assistant_turn_';
    // Refreshed at each step of the turn, so that a lock store whose locks expire keeps it for a turn of any length, each step being far shorter
    private const TURN_LOCK_TTL_SECONDS = 600.0;

    public function __construct(
        private readonly AssistantManager $assistantManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        #[Autowire('%tknoweb_ai_sql_assistant.entities%')]
        private readonly array $entities,
    ) {
    }

    /**
     * Conversations of $owner that are not archived, the most recently continued first.
     */
    public function getConversations(UserInterface $owner): array
    {
        $repository = $this->entityManager->getRepository($this->entities['conversation']);
        if (!$repository instanceof ConversationRepositoryInterface) {
            throw new \LogicException(sprintf('The repository of "%s" must implement %s.', $this->entities['conversation'], ConversationRepositoryInterface::class));
        }

        return $repository->findForOwner($owner);
    }

    /**
     * Conversation $id of $owner, null when it does not exist, is archived or belongs to another user: a conversation is only ever shown to its owner.
     */
    public function getOwnConversation(UserInterface $owner, int $id): ?ConversationInterface
    {
        $conversation = $this->entityManager->find($this->entities['conversation'], $id);
        if (!$conversation instanceof ConversationInterface || $conversation->isArchived() || $conversation->getOwner()->getUserIdentifier() !== $owner->getUserIdentifier()) {
            return null;
        }

        return $conversation;
    }

    /**
     * New conversation of $owner on the model $modelKey, titled after its first question. It is only persisted: requestTurn() or continueConversation() saves it along with its first turn.
     */
    public function createConversation(UserInterface $owner, string $modelKey, string $question): ConversationInterface
    {
        if (!in_array($modelKey, $this->assistantManager->getModelKeys(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown assistant model "%s".', $modelKey));
        }

        $conversation = (new $this->entities['conversation']())
            ->setOwner($owner)
            ->setModelKey($modelKey)
            ->setTitle($this->getTitle($question));

        $this->entityManager->persist($conversation);

        return $conversation;
    }

    /**
     * Run a turn of the conversation from the user input, save the updated history and the cost of the turn along with the log of the turn, its duration included, and return the events of
     * the turn to display. $onProgress receives each step of the turn (see AssistantManager::continueConversation()). The pending turn of the conversation, if any, ends with it.
     */
    public function continueConversation(ConversationInterface $conversation, string $userInput, ?\Closure $onProgress = null): array
    {
        $answeredQuestion = $this->assistantManager->isWaitingForAnswer($conversation->getHistory());
        $startTime = hrtime(true);
        $turn = $this->assistantManager->continueConversation($conversation->getHistory(), $conversation->getModelKey(), $userInput, $onProgress);
        $duration = intdiv(hrtime(true) - $startTime, 1000000);

        $conversation
            ->setHistory($turn['history'])
            ->addUsage($turn['usage'])
            ->endTurn();

        $exchange = (new $this->entities['exchange']())
            ->setConversation($conversation)
            ->setUserInput($userInput)
            ->setAnsweredQuestion($answeredQuestion)
            ->setResponse($this->getLoggedEvents($turn['events']))
            ->setUsage($turn['usage'])
            ->setDuration($duration);

        $this->entityManager->persist($exchange);
        $this->entityManager->flush();

        return $turn['events'];
    }

    /**
     * Save $userInput as the pending turn of the conversation, which runPendingTurn() runs afterwards, in place of a failed turn. A new conversation is saved with it, so that its owner finds
     * it again while its first turn runs.
     */
    public function requestTurn(ConversationInterface $conversation, string $userInput): void
    {
        if (in_array($this->getTurnState($conversation), self::TURN_IN_PROGRESS_STATES, true)) {
            throw new \LogicException(sprintf('The conversation %d already has a turn in progress.', $conversation->getId()));
        }

        $conversation->requestTurn($userInput);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
    }

    /**
     * Run the pending turn of the conversation when it waits for a request, saving each step it goes through at once so that the page can show it. The lock of the turn is held until its end,
     * so that two requests never run it both and that a turn whose request died is seen as failed. Whatever stops the turn before its end, a failure of the model API for instance, leaves it
     * failed, its message kept to be sent again. Nothing happens when the turn does not wait for a request, the result telling whether this call ran it.
     */
    public function runPendingTurn(ConversationInterface $conversation): bool
    {
        $lock = $this->createTurnLock($conversation);
        if (!$lock->acquire()) {
            return false;
        }

        try {
            // Another request may have run the turn between the loading of the conversation and the lock
            $this->entityManager->refresh($conversation);
            if (self::TURN_WAITING !== $this->getTurnState($conversation)) {
                return false;
            }

            $conversation->startTurn();
            $this->entityManager->flush();

            $this->continueConversation($conversation, $conversation->getPendingInput(), function (array $step) use ($conversation, $lock): void {
                $conversation->addTurnStep($step);
                $this->entityManager->flush();
                $lock->refresh();
            });

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * State of the pending turn of the conversation, null when it has none: waiting for the request that runs it, running, or failed. A turn is failed when it started but nobody holds its
     * lock anymore, whether the model API failed or its request died (a restart of the web server, for instance).
     */
    public function getTurnState(ConversationInterface $conversation): ?string
    {
        if (null === $conversation->getPendingInput()) {
            return null;
        }

        if (null === $conversation->getTurnStartedAt()) {
            return self::TURN_WAITING;
        }

        $lock = $this->createTurnLock($conversation);
        if (!$lock->acquire()) {
            return self::TURN_RUNNING;
        }
        $lock->release();

        return self::TURN_FAILED;
    }

    /**
     * Give a conversation the title its owner typed, shortened as the one taken from its first question. A blank title is refused, so that a conversation always keeps one.
     */
    public function renameConversation(ConversationInterface $conversation, string $title): void
    {
        if ('' === trim($title)) {
            throw new \InvalidArgumentException('The title of a conversation cannot be blank.');
        }

        $conversation->setTitle($this->getTitle($title));
        $this->entityManager->flush();
    }

    /**
     * Remove a conversation from the view of its owner. It is only archived, so that its history and its logged exchanges stay in the database.
     */
    public function archiveConversation(ConversationInterface $conversation): void
    {
        $conversation->archive();
        $this->entityManager->flush();
    }

    /**
     * Exchanges of a period, exported for their weekly review: what the users typed and what the assistant did, with the costs, but no database value (see getLoggedEvents()).
     * Users are only identified by a pseudonym, a hash of their identifier, the review needing to tell them apart but not to know who they are.
     */
    public function getExchangesExport(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $repository = $this->entityManager->getRepository($this->entities['exchange']);
        if (!$repository instanceof ConversationExchangeRepositoryInterface) {
            throw new \LogicException(sprintf('The repository of "%s" must implement %s.', $this->entities['exchange'], ConversationExchangeRepositoryInterface::class));
        }

        $exchanges = $repository->findBetween($from, $to);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'exchangeCount' => count($exchanges),
            'totalCost' => array_sum(array_map(fn (ConversationExchangeInterface $exchange) => $exchange->getCost(), $exchanges)),
            'exchanges' => array_map(fn (ConversationExchangeInterface $exchange) => [
                'id' => $exchange->getId(),
                'createdAt' => $exchange->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'conversationId' => $exchange->getConversation()->getId(),
                'conversationTitle' => $exchange->getConversation()->getTitle(),
                'conversationModelKey' => $exchange->getConversation()->getModelKey(),
                'ownerPseudonym' => substr(hash('sha256', $exchange->getConversation()->getOwner()->getUserIdentifier()), 0, 12),
                'userInput' => $exchange->getUserInput(),
                'answeredQuestion' => $exchange->isAnsweredQuestion(),
                'response' => $exchange->getResponse(),
                'models' => $exchange->getModels(),
                'inputTokens' => $exchange->getInputTokens(),
                'outputTokens' => $exchange->getOutputTokens(),
                'cacheCreationInputTokens' => $exchange->getCacheCreationInputTokens(),
                'cacheReadInputTokens' => $exchange->getCacheReadInputTokens(),
                'cost' => $exchange->getCost(),
                'durationMilliseconds' => $exchange->getDuration(),
            ], $exchanges),
        ];
    }

    private function createTurnLock(ConversationInterface $conversation): LockInterface
    {
        return $this->lockFactory->createLock(self::TURN_LOCK_PREFIX.$conversation->getId(), self::TURN_LOCK_TTL_SECONDS);
    }

    /**
     * Title of a conversation from a typed text, trimmed and shortened to the length of its column.
     */
    private function getTitle(string $text): string
    {
        return mb_strimwidth(trim($text), 0, AbstractConversation::TITLE_MAX_LENGTH, '…');
    }

    /**
     * Events of a turn as they are logged: a query keeps its SQL, interpretation, format and error, but neither its rows nor its answer sentence, which hold database values.
     */
    private function getLoggedEvents(array $events): array
    {
        return array_map(function (array $event) {
            if (AssistantManager::EVENT_QUERY === $event['type']) {
                unset($event['result'], $event['answer']);
            }

            return $event;
        }, $events);
    }
}
