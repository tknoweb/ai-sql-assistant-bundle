<?php

namespace Tknoweb\AiSqlAssistantBundle\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 */
class ChatController extends AbstractController
{
    public const TRANSLATION_DOMAIN = 'TknowebAiSqlAssistant';

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

            if ($this->runTurn($conversation, $question)) {
                return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $conversation->getId()]);
            }
        }

        return $this->renderPage('@TknowebAiSqlAssistant/chat/index.html.twig', [
            'form' => $form,
            'conversations' => $this->conversationManager->getConversations($user),
        ], $this->getFormResponse($form));
    }

    /**
     * A conversation and the form continuing it, which answers the pending question of the assistant when there is one.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->getAllowedUser();
        $conversation = $this->getOwnConversation($user, $id);
        $history = $conversation->getHistory();

        $form = $this->createForm(MessageType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->runTurn($conversation, $form->get('message')->getData())) {
            return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $conversation->getId()]);
        }

        return $this->renderPage('@TknowebAiSqlAssistant/chat/show.html.twig', [
            'entity' => $conversation,
            'conversation' => $conversation,
            'conversations' => $this->conversationManager->getConversations($user),
            'timeline' => $this->assistantManager->getTimeline($history),
            'waitingForAnswer' => $this->assistantManager->isWaitingForAnswer($history),
            'questionCount' => min($this->assistantManager->getRequestQuestionCount($history), AssistantManager::MAX_QUESTIONS_PER_REQUEST),
            'maxQuestionCount' => AssistantManager::MAX_QUESTIONS_PER_REQUEST,
            'form' => $form,
        ], $this->getFormResponse($form));
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

        return $this->redirectToCurrentPage($request, $user);
    }

    #[Route('/{id}/archive', name: 'archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        $user = $this->getAllowedUser();
        $conversation = $this->getOwnConversation($user, $id);
        $this->checkCsrfToken($request);

        $this->conversationManager->archiveConversation($conversation);
        $this->addFlash('success', $this->translator->trans('conversationArchived', domain: self::TRANSLATION_DOMAIN));

        return $this->redirectToCurrentPage($request, $user);
    }

    /**
     * Run a turn of the conversation. An error of the model API is shown to the user instead of an error page, and leaves the conversation as it was: nothing of the turn is saved.
     */
    private function runTurn(ConversationInterface $conversation, string $userInput): bool
    {
        set_time_limit(self::TURN_TIME_LIMIT_SECONDS);

        try {
            $this->conversationManager->continueConversation($conversation, $userInput);
        } catch (ModelProviderException $exception) {
            $this->logger->error('The assistant turn failed on the model API: '.$exception->getMessage(), ['exception' => $exception]);
            $this->addFlash('danger', $this->translator->trans('error', domain: self::TRANSLATION_DOMAIN));

            return false;
        }

        return true;
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
     * Page the user renamed or archived a conversation from: the conversation they were reading, posted as "currentId" by the history, unless that is the one they archived, else the home page.
     */
    private function redirectToCurrentPage(Request $request, UserInterface $user): Response
    {
        $currentId = $request->request->getInt('currentId');
        if (0 !== $currentId && null !== $this->conversationManager->getOwnConversation($user, $currentId)) {
            return $this->redirectToRoute($this->routeNamePrefix.'show', ['id' => $currentId]);
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
