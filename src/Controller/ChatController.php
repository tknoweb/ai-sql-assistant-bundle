<?php

namespace Tknoweb\AiSqlAssistantBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationInterface;
use Tknoweb\AiSqlAssistantBundle\Form\MessageType;
use Tknoweb\AiSqlAssistantBundle\Form\NewConversationType;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ConversationManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ResultManager;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;

/**
 * Chat of the assistant: each user only ever reaches their own conversations, which they can rename and archive (the log keeps them). Its routes are imported by the application, which sets their path
 * prefix and their name prefix, the latter also set in the "route_name_prefix" configuration so that the controller and the templates can build them.
 * The pages are made of two Turbo frames, the history and the main column, so that most actions only reload one of them. Every action still answers a page without Turbo.
 */
class ChatController extends AbstractController
{
    public const TRANSLATION_DOMAIN = 'TknowebAiSqlAssistant';

    // Turbo frame of the history of the conversations, next to the main column ("ai-sql-assistant-main")
    public const HISTORY_FRAME = 'ai-sql-assistant-history';

    // A turn can chain several calls to the model API and several queries, far beyond the default PHP time limit
    private const TURN_TIME_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly ConversationManager $conversationManager,
        private readonly AssistantManager $assistantManager,
        private readonly ResultManager $resultManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%tknoweb_ai_sql_assistant.access_attribute%')]
        private readonly string $accessAttribute,
        #[Autowire('%tknoweb_ai_sql_assistant.route_name_prefix%')]
        private readonly string $routeNamePrefix,
        #[Autowire('%tknoweb_ai_sql_assistant.csrf_token_id%')]
        private readonly string $csrfTokenId,
        #[Autowire('%tknoweb_ai_sql_assistant.base_template%')]
        private readonly string $baseTemplate,
    ) {
    }

    /**
     * History of the conversations of the user and the form starting a new one.
     */
    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $user = $this->getAllowedUser();

        $form = $this->createForm(NewConversationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $question = $form->get('question')->getData();
            $conversation = $this->conversationManager->createConversation($user, $this->assistantManager->getDefaultModelKey(), $question);
            $this->conversationManager->requestTurn($conversation, $question);

            return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $conversation->getId()]);
        }

        return $this->renderPage('@TknowebAiSqlAssistant/chat/index.html.twig', [
            'form' => $form,
            'conversations' => $this->conversationManager->getConversations($user),
        ], $this->getFormResponse($form));
    }

    /**
     * A conversation and the form continuing it, which answers the pending question of the assistant when there is one. While a turn waits or runs, the page shows its steps and the form is
     * disabled; after a failed turn, the form offers its message again.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->getAllowedUser();
        $conversation = $this->getOwnConversation($user, $id);
        $history = $conversation->getHistory();
        $turnState = $this->conversationManager->getTurnState($conversation);
        $turnInProgress = in_array($turnState, ConversationManager::TURN_IN_PROGRESS_STATES, true);

        $form = $this->createForm(MessageType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $turnInProgress) {
            // A page left open since before the turn started may still post its form
            $form->get('message')->addError(new FormError($this->translator->trans('turnInProgress', domain: self::TRANSLATION_DOMAIN)));
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $this->conversationManager->requestTurn($conversation, $form->get('message')->getData());

            return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $conversation->getId()]);
        } elseif (!$form->isSubmitted() && ConversationManager::TURN_FAILED === $turnState) {
            $form->get('message')->setData($conversation->getPendingInput());
        }

        return $this->renderPage('@TknowebAiSqlAssistant/chat/show.html.twig', [
            'entity' => $conversation,
            'conversation' => $conversation,
            'conversations' => $this->conversationManager->getConversations($user),
            'timeline' => $this->assistantManager->getTimeline($history),
            'turnState' => $turnState,
            'turnInProgress' => $turnInProgress,
            // The question a running turn answers offers its options no more
            'waitingForAnswer' => !$turnInProgress && $this->assistantManager->isWaitingForAnswer($history),
            'questionCount' => min($this->assistantManager->getRequestQuestionCount($history), AssistantManager::MAX_QUESTIONS_PER_REQUEST),
            'maxQuestionCount' => AssistantManager::MAX_QUESTIONS_PER_REQUEST,
            'form' => $form,
        ], $this->getFormResponse($form));
    }

    /**
     * Run the pending turn of the conversation, called by its page once the turn is saved. The turn goes on until its end even when the user leaves the page, which follows it through the
     * progress route rather than through this response.
     */
    #[Route('/{id}/run', name: 'run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function run(Request $request, int $id): Response
    {
        $conversation = $this->getOwnConversation($this->getAllowedUser(), $id);
        $this->checkCsrfToken($request);

        // Released first, so that the other pages of the user do not wait for the end of the turn
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
        ignore_user_abort(true);
        set_time_limit(self::TURN_TIME_LIMIT_SECONDS);

        try {
            $this->conversationManager->runPendingTurn($conversation);
        } catch (ModelProviderException $exception) {
            // The page learns it from the state of the turn, failed from now on
            $this->logger->error('The assistant turn failed on the model API: '.$exception->getMessage(), ['exception' => $exception]);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Turn frame of the conversation page, reloaded while the turn waits or runs. Once the turn is over, whether it ended or failed, the frame only holds a mark telling the page to reload
     * the conversation.
     */
    #[Route('/{id}/progress', name: 'progress', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function progress(int $id): Response
    {
        $conversation = $this->getOwnConversation($this->getAllowedUser(), $id);
        $turnState = $this->conversationManager->getTurnState($conversation);

        return $this->renderPage('@TknowebAiSqlAssistant/chat/progress.html.twig', [
            'conversation' => $conversation,
            'turnState' => in_array($turnState, ConversationManager::TURN_IN_PROGRESS_STATES, true) ? $turnState : null,
        ]);
    }

    /**
     * History frame of the chat pages, which they reload once their main column changed, "current" being the conversation it shows.
     */
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $user = $this->getAllowedUser();
        $currentId = $request->query->getInt('current');

        return $this->renderPage('@TknowebAiSqlAssistant/chat/history.html.twig', [
            'conversations' => $this->conversationManager->getConversations($user),
            'currentConversation' => 0 !== $currentId ? $this->conversationManager->getOwnConversation($user, $currentId) : null,
        ]);
    }

    /**
     * Result of a query of the conversation, run again since results are never stored, in every format it allows. Loaded in its own turbo frame, so that a slow query does not hold the page.
     */
    #[Route('/{id}/query/{toolUseId}', name: 'query', requirements: ['id' => '\d+'])]
    public function query(int $id, string $toolUseId): Response
    {
        $conversation = $this->getOwnConversation($this->getAllowedUser(), $id);
        $query = $this->getStoredQuery($conversation, $toolUseId, AssistantManager::MAX_DISPLAYED_ROWS);

        $columns = $query['result']['columns'] ?? [];
        $rows = null !== $query['result'] ? $this->resultManager->getLabelledRows($query['result']) : [];

        return $this->renderPage('@TknowebAiSqlAssistant/chat/_query_result.html.twig', [
            'conversation' => $conversation,
            'query' => $query,
            'columns' => $columns,
            'columnLabels' => array_map(fn (string $column) => $this->resultManager->getColumnLabel($column), $columns),
            'rows' => $rows,
            'chart' => null !== $query['chart'] ? $this->resultManager->createChart($rows, $query['chart']) : null,
            'maxDisplayedRows' => AssistantManager::MAX_DISPLAYED_ROWS,
        ]);
    }

    /**
     * Excel file of a query of the conversation, run again with a far higher row limit than the display.
     */
    #[Route('/{id}/query/{toolUseId}/excel', name: 'excel', requirements: ['id' => '\d+'])]
    public function excel(int $id, string $toolUseId): Response
    {
        $query = $this->getStoredQuery($this->getOwnConversation($this->getAllowedUser(), $id), $toolUseId, ResultManager::MAX_EXPORTED_ROWS);
        if (null === $query['result']) {
            throw $this->createNotFoundException(sprintf('The query "%s" cannot run anymore.', $toolUseId));
        }

        return $this->resultManager->createSpreadsheetResponse($query['result']['columns'], $this->resultManager->getLabelledRows($query['result']), $query['title']);
    }

    #[Route('/{id}/rename', name: 'rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rename(Request $request, int $id): Response
    {
        $user = $this->getAllowedUser();
        $conversation = $this->getOwnConversation($user, $id);
        $this->checkCsrfToken($request);

        try {
            $this->conversationManager->renameConversation($conversation, $request->request->getString('title'));
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->redirectToCurrentPage($request, $user, $conversation);
    }

    #[Route('/{id}/archive', name: 'archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        $user = $this->getAllowedUser();
        $conversation = $this->getOwnConversation($user, $id);
        $this->checkCsrfToken($request);

        $this->conversationManager->archiveConversation($conversation);
        // Within a frame, the conversation leaving the history says it well enough, and the page would only show the message after its next full load
        if (!$request->headers->has('Turbo-Frame')) {
            $this->addFlash('success', $this->translator->trans('conversationArchived', domain: self::TRANSLATION_DOMAIN));
        }

        return $this->redirectToCurrentPage($request, $user, $conversation);
    }

    /**
     * Conversation of the current user, the ones of other users and the archived ones being reported as not found rather than forbidden.
     */
    private function getOwnConversation(UserInterface $user, int $id): ConversationInterface
    {
        return $this->conversationManager->getOwnConversation($user, $id) ?? throw $this->createNotFoundException(sprintf('No conversation %d for the current user.', $id));
    }

    private function checkCsrfToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid($this->csrfTokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Page the user renamed or archived $conversation from: the conversation they were reading, posted as "currentId" by the history, unless that is the one they archived, else the home page.
     * Posted from the history frame about another conversation, only that frame is reloaded. Turbo names in "Turbo-Frame" the frame holding the form, not the one it targets: the history
     * forms of the conversation being read target the main column, which shows it as well and reloads the history in turn.
     */
    private function redirectToCurrentPage(Request $request, UserInterface $user, ConversationInterface $conversation): Response
    {
        // Empty when posted from the home page, which getInt() would refuse
        $currentId = (int) $request->request->getString('currentId');
        $current = 0 !== $currentId ? $this->conversationManager->getOwnConversation($user, $currentId) : null;

        if (self::HISTORY_FRAME === $request->headers->get('Turbo-Frame') && $conversation->getId() !== $currentId) {
            return $this->redirectToRoute($this->routeNamePrefix.'history', null !== $current ? ['current' => $current->getId()] : []);
        }

        if (null !== $current) {
            return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $current->getId()]);
        }

        return $this->redirectToRoute($this->routeNamePrefix.'index');
    }

    private function getStoredQuery(ConversationInterface $conversation, string $toolUseId, int $maxRows): array
    {
        try {
            return $this->assistantManager->runStoredQuery($conversation->getHistory(), $toolUseId, $maxRows);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }
    }

    /**
     * Current user, once checked against the "access_attribute" configuration of the bundle.
     */
    private function getAllowedUser(): UserInterface
    {
        $this->denyAccessUnlessGranted($this->accessAttribute);

        return $this->getUser() ?? throw $this->createAccessDeniedException('The assistant needs an authenticated user.');
    }

    /**
     * Render a template of the bundle with the variables all of them share: the layout they extend, the name prefix of the routes and the CSRF token id of the renaming and the archiving.
     */
    private function renderPage(string $template, array $parameters, ?Response $response = null): Response
    {
        return $this->render($template, $parameters + [
            'baseTemplate' => $this->baseTemplate,
            'routePrefix' => $this->routeNamePrefix,
            'csrfTokenId' => $this->csrfTokenId,
        ], $response);
    }

    /**
     * A form submitted but not saved is rendered again with a 422 status, the one Turbo expects to display the page instead of waiting for a redirection.
     */
    private function getFormResponse(FormInterface $form): Response
    {
        return new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
