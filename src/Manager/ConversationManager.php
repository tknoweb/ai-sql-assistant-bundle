<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\User\UserInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationRepositoryInterface;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractConversation;

/**
 * Stores the conversations of the assistant in the entities set in the "entities" configuration of the bundle: AssistantManager runs each turn from the stored history, this manager keeps the
 * history and the cost up to date and logs every turn as an exchange.
 */
class ConversationManager
{
    public function __construct(
        private readonly AssistantManager $assistantManager,
        private readonly EntityManagerInterface $entityManager,
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
     * New conversation of $owner on the model $modelKey, titled after its first question. It is only persisted: the first turn, run by continueConversation(), saves it along with its history,
     * so that a failed first call to the API leaves nothing behind.
     */
    public function createConversation(UserInterface $owner, string $modelKey, string $question): ConversationInterface
    {
        if (!in_array($modelKey, $this->assistantManager->getModelKeys(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown assistant model "%s".', $modelKey));
        }

        $conversation = (new $this->entities['conversation']())
            ->setOwner($owner)
            ->setModelKey($modelKey)
            ->setTitle(mb_strimwidth(trim($question), 0, AbstractConversation::TITLE_MAX_LENGTH, '…'));

        $this->entityManager->persist($conversation);

        return $conversation;
    }

    /**
     * Run a turn of the conversation from the user input, save the updated history and the cost of the turn along with the log of the turn, and return the events of the turn to display.
     */
    public function continueConversation(ConversationInterface $conversation, string $userInput): array
    {
        $answeredQuestion = $this->assistantManager->isWaitingForAnswer($conversation->getHistory());
        $turn = $this->assistantManager->continueConversation($conversation->getHistory(), $conversation->getModelKey(), $userInput);

        $conversation
            ->setHistory($turn['history'])
            ->addUsage($turn['usage']);

        $exchange = (new $this->entities['exchange']())
            ->setConversation($conversation)
            ->setUserInput($userInput)
            ->setAnsweredQuestion($answeredQuestion)
            ->setResponse($this->getLoggedEvents($turn['events']))
            ->setUsage($turn['usage']);

        $this->entityManager->persist($exchange);
        $this->entityManager->flush();

        return $turn['events'];
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
            ], $exchanges),
        ];
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
