<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Exception as DBALException;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tknoweb\AiSqlAssistantBundle\Contract\ModelProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ReferentialProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Provider\ToolCall;

/**
 * Conversation loop of the assistant, on the model API of the provider of each model (see the "providers" configuration of the bundle). The loop is written by hand rather than with a tool
 * runner, because the ask_user tool pauses the conversation until the user answers, which happens in another HTTP request.
 * The model never sees any data: the result of run_query is displayed to the user by the application, and only a SQL error message goes back to the model. The public referentials of the
 * application, when it offers some, are the only data it can read, the table descriptions and the catalog of the document fields holding names and labels only.
 */
class AssistantManager
{
    public const TOOL_ASK_USER = 'ask_user';
    public const TOOL_RUN_QUERY = 'run_query';
    public const TOOL_SEARCH_PUBLIC_REFERENTIAL = 'search_public_referential';
    public const TOOL_DESCRIBE_TABLES = 'describe_tables';
    public const TOOL_SEARCH_DOCUMENT_FIELDS = 'search_document_fields';

    // Message of the user, or answer to a question of the model, only found in the timeline of a stored conversation: during a turn the caller already knows what the user typed
    public const EVENT_USER_MESSAGE = 'userMessage';
    public const EVENT_TEXT = 'text';
    public const EVENT_QUESTION = 'question';
    public const EVENT_QUERY = 'query';
    public const EVENT_REFUSAL = 'refusal';
    public const EVENT_INTERRUPTED = 'interrupted';

    // Formats a query result can be shown in. The model picks the first one from the request, the user can then switch to any other one available for that result.
    public const OUTPUT_TEXT = 'text';
    public const OUTPUT_TABLE = 'table';
    public const OUTPUT_CHART = 'chart';
    public const OUTPUT_EXCEL = 'excel';
    public const OUTPUTS = [self::OUTPUT_TEXT, self::OUTPUT_TABLE, self::OUTPUT_CHART, self::OUTPUT_EXCEL];

    public const CHART_TYPES = ['bar', 'line', 'pie'];

    // Placeholder of the answer template, which the application fills with the single value of the result since the model never sees it
    public const ANSWER_VALUE_PLACEHOLDER = '{value}';

    // Rows of a query result kept for the display, the Excel export being meant to run the query again without this limit
    public const MAX_DISPLAYED_ROWS = 500;

    // Questions the model may ask for a single request, the user answers not counting as new requests
    public const MAX_QUESTIONS_PER_REQUEST = 10;

    private const MAX_TOKENS = 16000;
    // Safety net against a model looping on its tools: beyond it, the turn stops and the user is told so. A turn may take a few table descriptions and field searches before its query.
    private const MAX_MODEL_CALLS_PER_TURN = 12;
    private const MAX_DESCRIBED_TABLES = 10;

    private const QUERY_SUCCESS_RESULT = 'The query ran successfully. Its result is displayed to the user, you cannot see it.';
    private const INTERRUPTED_TOOL_RESULT = 'This tool call was not run, the turn was interrupted before it.';
    private const QUESTION_LIMIT_TOOL_RESULT = 'The question limit of this request is reached, this question was not asked: apply the default rules of the dictionary to the notions left, state them in the interpretation, and run the query.';

    public function __construct(
        #[Autowire(service: 'tknoweb_ai_sql_assistant.provider_locator')]
        private readonly ContainerInterface $providers,
        private readonly PromptManager $promptManager,
        private readonly QueryManager $queryManager,
        private readonly SchemaManager $schemaManager,
        private readonly JsonCatalogManager $catalogManager,
        #[Autowire('%tknoweb_ai_sql_assistant.models%')]
        private readonly array $models,
        #[Autowire('%tknoweb_ai_sql_assistant.model_prices%')]
        private readonly array $modelPrices,
        #[Autowire('%tknoweb_ai_sql_assistant.default_model%')]
        private readonly string $defaultModelKey,
        private readonly ?ReferentialProviderInterface $referentialProvider = null,
    ) {
    }

    /**
     * Key of the model new conversations run on, as set in the "default_model" configuration of the bundle.
     */
    public function getDefaultModelKey(): string
    {
        if (!isset($this->models[$this->defaultModelKey])) {
            throw new \LogicException(sprintf('The default model "%s" is missing from the "models" configuration of the assistant.', $this->defaultModelKey));
        }

        return $this->defaultModelKey;
    }

    /**
     * Keys of the models a conversation can run on, as set in the "models" configuration of the bundle.
     */
    public function getModelKeys(): array
    {
        return array_keys($this->models);
    }

    /**
     * Add the user input to the conversation, then let the model work until it needs the user again, either to answer one of its questions or to read its answer.
     * $history is the history returned by the previous call, empty for a new conversation, and must be stored as is in between. When it ends on a question of the model, $userInput is the
     * answer to that question. The model must stay the same for the whole conversation, since switching it would invalidate the prompt cache.
     * The returned array holds the updated history, the events of the turn in their order (texts, question, queries with their result) for the display, and the usage of the turn: the models
     * that answered, the tokens used and their cost in dollars. Each model message of the history records the provider that wrote it, which is the only one able to read it back.
     */
    public function continueConversation(array $history, string $modelKey, string $userInput): array
    {
        $model = $this->models[$modelKey] ?? throw new \InvalidArgumentException(sprintf('Unknown assistant model "%s".', $modelKey));

        $pendingQuestion = $this->getLastToolUse($history);
        $history[] = [
            'role' => 'user',
            'content' => null !== $pendingQuestion && self::TOOL_ASK_USER === $pendingQuestion->name
                ? [$this->getToolResult($pendingQuestion->id, $userInput)]
                : $userInput,
        ];

        $events = [];
        $usage = ['models' => [], 'inputTokens' => 0, 'outputTokens' => 0, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0, 'cost' => 0.0];

        $provider = $this->getProvider($model['provider']);
        for ($callCount = 1;; ++$callCount) {
            $message = $provider->createMessage($model['id'], $this->promptManager->getSystemTexts(), $history, $this->getToolDefinitions(), self::MAX_TOKENS);
            $history[] = ['role' => 'assistant', 'provider' => $model['provider'], 'message' => $message->raw];
            $this->addUsage($usage, $message, $model['id']);

            foreach ($message->texts as $text) {
                if ('' !== trim($text)) {
                    $events[] = ['type' => self::EVENT_TEXT, 'text' => $text];
                }
            }

            $toolUse = $message->toolCall;
            if (ModelMessage::STOP_TOOL_USE !== $message->stopReason || null === $toolUse) {
                if (ModelMessage::STOP_REFUSAL === $message->stopReason) {
                    $events[] = ['type' => self::EVENT_REFUSAL];
                } elseif (ModelMessage::STOP_MAX_TOKENS === $message->stopReason) {
                    $events[] = ['type' => self::EVENT_INTERRUPTED];
                }

                break;
            }

            // The question cap is enforced here rather than left to the model: beyond it, the question is refused and the model goes on with the default rules
            if (self::TOOL_ASK_USER === $toolUse->name && $this->getRequestQuestionCount($history) <= self::MAX_QUESTIONS_PER_REQUEST) {
                $events[] = $this->getQuestionEvent($toolUse);

                break;
            }

            if ($callCount >= self::MAX_MODEL_CALLS_PER_TURN) {
                $events[] = ['type' => self::EVENT_INTERRUPTED];

                break;
            }

            [$toolResult, $event] = self::TOOL_ASK_USER === $toolUse->name
                ? [$this->getToolResult($toolUse->id, self::QUESTION_LIMIT_TOOL_RESULT, true), null]
                : $this->runTool($toolUse);
            if (null !== $event) {
                $events[] = $event;
            }

            $history[] = ['role' => 'user', 'content' => [$toolResult]];
        }

        $this->closeUnansweredToolUse($history);

        return [
            'history' => $history,
            'events' => $events,
            'usage' => $usage,
        ];
    }

    /**
     * Questions the model asked since the last request of the user, the answers to them not being new requests. A question refused for exceeding the cap is counted as well.
     */
    public function getRequestQuestionCount(array $history): int
    {
        $questionCount = 0;
        foreach (array_reverse($history) as $entry) {
            if ('user' === $entry['role'] && is_string($entry['content'])) {
                break;
            }

            if ('assistant' === $entry['role'] && self::TOOL_ASK_USER === $this->readMessage($entry)->toolCall?->name) {
                ++$questionCount;
            }
        }

        return $questionCount;
    }

    /**
     * Whether the conversation stopped on a question of the model, the next user input being then the answer to it.
     */
    public function isWaitingForAnswer(array $history): bool
    {
        return self::TOOL_ASK_USER === $this->getLastToolUse($history)?->name;
    }

    /**
     * Events of a stored conversation, in their order, to display it again: messages of the user, texts and questions of the model, queries, refusals and interruptions.
     * The queries come without their result, which is never stored: runStoredQuery() runs one of them again from its "toolUseId".
     */
    public function getTimeline(array $history): array
    {
        $events = [];
        // Name of the tool of each call, to know what the tool results of the following user messages answer
        $toolNames = [];

        foreach ($history as $entry) {
            if ('assistant' === $entry['role']) {
                $message = $this->readMessage($entry);
                foreach ($message->texts as $text) {
                    if ('' !== trim($text)) {
                        $events[] = ['type' => self::EVENT_TEXT, 'text' => $text];
                    }
                }

                $toolCall = $message->toolCall;
                if (null !== $toolCall) {
                    $toolNames[$toolCall->id] = $toolCall->name;
                    if (self::TOOL_ASK_USER === $toolCall->name) {
                        $events[] = $this->getQuestionEvent($toolCall);
                    } elseif (self::TOOL_RUN_QUERY === $toolCall->name) {
                        $events[] = [
                            'type' => self::EVENT_QUERY,
                            'toolUseId' => $toolCall->id,
                            'title' => (string) ($toolCall->input['title'] ?? ''),
                            'interpretation' => (string) ($toolCall->input['interpretation'] ?? ''),
                            'sql' => (string) ($toolCall->input['sql'] ?? ''),
                            'error' => null,
                        ];
                    }
                }

                if (ModelMessage::STOP_REFUSAL === $message->stopReason) {
                    $events[] = ['type' => self::EVENT_REFUSAL];
                } elseif (ModelMessage::STOP_MAX_TOKENS === $message->stopReason && null === $toolCall) {
                    // A truncated call is followed by an interrupted tool result, which gives the interruption event below
                    $events[] = ['type' => self::EVENT_INTERRUPTED];
                }

                continue;
            }

            if (is_string($entry['content'])) {
                $events[] = ['type' => self::EVENT_USER_MESSAGE, 'text' => $entry['content']];

                continue;
            }

            foreach ($entry['content'] as $toolResult) {
                $toolName = $toolNames[$toolResult['toolUseID']] ?? null;
                if (self::INTERRUPTED_TOOL_RESULT === $toolResult['content']) {
                    // A call the turn stopped before running was never run nor shown, a query as well as a question
                    $events = $this->removeToolCallEvent($events, $toolResult['toolUseID']);
                    $events[] = ['type' => self::EVENT_INTERRUPTED];
                } elseif (self::QUESTION_LIMIT_TOOL_RESULT === $toolResult['content']) {
                    // A question refused for exceeding the cap was never shown to the user
                    $events = $this->removeToolCallEvent($events, $toolResult['toolUseID']);
                } elseif (self::TOOL_ASK_USER === $toolName) {
                    $events[] = ['type' => self::EVENT_USER_MESSAGE, 'text' => $toolResult['content']];
                } elseif (self::TOOL_RUN_QUERY === $toolName && ($toolResult['isError'] ?? false)) {
                    $events = $this->setQueryEventError($events, $toolResult['toolUseID'], $toolResult['content']);
                }
            }
        }

        return $events;
    }

    /**
     * Plain transcript of a conversation, to read it in full: user messages, texts of the model, and every tool call with its result, the tool results never holding any database value.
     * Each turn is ["role" => user|assistant|tool_call|tool_result, "content" => string], a tool call carrying its tool "name" and its input as pretty printed JSON.
     */
    public function getTranscript(array $history): array
    {
        $transcript = [];
        foreach ($history as $entry) {
            if ('assistant' === $entry['role']) {
                $message = $this->readMessage($entry);
                foreach ($message->texts as $text) {
                    if ('' !== trim($text)) {
                        $transcript[] = ['role' => 'assistant', 'content' => $text];
                    }
                }
                if (null !== $message->toolCall) {
                    $transcript[] = ['role' => 'tool_call', 'name' => $message->toolCall->name, 'content' => json_encode($message->toolCall->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)];
                }

                continue;
            }

            if (is_string($entry['content'])) {
                $transcript[] = ['role' => 'user', 'content' => $entry['content']];

                continue;
            }

            foreach ($entry['content'] as $toolResult) {
                $transcript[] = ['role' => 'tool_result', 'content' => (string) $toolResult['content']];
            }
        }

        return $transcript;
    }

    /**
     * Number of calls of each tool in a conversation, by tool name.
     */
    public function getToolCallCounts(array $history): array
    {
        $toolCallCounts = [];
        foreach ($history as $entry) {
            if ('assistant' !== $entry['role']) {
                continue;
            }

            $toolCall = $this->readMessage($entry)->toolCall;
            if (null !== $toolCall) {
                $toolCallCounts[$toolCall->name] = ($toolCallCounts[$toolCall->name] ?? 0) + 1;
            }
        }

        return $toolCallCounts;
    }

    /**
     * Run again a query of a stored conversation, from the "toolUseId" given by getTimeline(), and return its event with its result as during the turn that ran it first.
     */
    public function runStoredQuery(array $history, string $toolUseId, int $maxRows = self::MAX_DISPLAYED_ROWS): array
    {
        foreach ($history as $entry) {
            if ('assistant' !== $entry['role']) {
                continue;
            }

            $toolCall = $this->readMessage($entry)->toolCall;
            if (null !== $toolCall && self::TOOL_RUN_QUERY === $toolCall->name && $toolUseId === $toolCall->id) {
                return $this->getQueryEvent($toolCall->input, $maxRows) + ['toolUseId' => $toolUseId];
            }
        }

        throw new \InvalidArgumentException(sprintf('The conversation holds no query "%s".', $toolUseId));
    }

    private function removeToolCallEvent(array $events, string $toolUseId): array
    {
        return array_values(array_filter($events, fn (array $event) => $toolUseId !== ($event['toolUseId'] ?? null)));
    }

    private function setQueryEventError(array $events, string $toolUseId, string $error): array
    {
        foreach ($events as $index => $event) {
            if (self::EVENT_QUERY === $event['type'] && $toolUseId === $event['toolUseId']) {
                $events[$index]['error'] = $error;
            }
        }

        return $events;
    }

    private function getQuestionEvent(ToolCall $toolUse): array
    {
        return [
            'type' => self::EVENT_QUESTION,
            'toolUseId' => $toolUse->id,
            'question' => (string) ($toolUse->input['question'] ?? ''),
            'options' => array_values(array_map('strval', (array) ($toolUse->input['options'] ?? []))),
        ];
    }

    private function getProvider(string $name): ModelProviderInterface
    {
        if (!$this->providers->has($name)) {
            throw new \LogicException(sprintf('The provider "%s" is missing from the "providers" configuration of the assistant.', $name));
        }

        return $this->providers->get($name);
    }

    /**
     * Neutral reading of a model message of the history, by the provider that wrote it. A message stored before the providers were recorded was written by the provider of the default model.
     */
    private function readMessage(array $entry): ModelMessage
    {
        return $this->getProvider($entry['provider'] ?? $this->models[$this->getDefaultModelKey()]['provider'])->readMessage($entry['message']);
    }

    /**
     * Tool call of the last message of the history, when that message is a model message: a pending question of the model, or a call the turn stopped before running.
     */
    private function getLastToolUse(array $history): ?ToolCall
    {
        $lastEntry = end($history);
        if (false === $lastEntry || 'assistant' !== $lastEntry['role']) {
            return null;
        }

        return $this->readMessage($lastEntry)->toolCall;
    }

    /**
     * Every tool call needs a result in the next message, otherwise the API refuses the conversation. A turn stopped before running a call (too many calls, truncated answer) therefore
     * gets an error result for it, so that the next user message can follow. A pending question is left as it is, the answer of the user being its result, unless its answer was truncated.
     */
    private function closeUnansweredToolUse(array &$history): void
    {
        $lastEntry = end($history);
        if (false === $lastEntry || 'assistant' !== $lastEntry['role']) {
            return;
        }

        $message = $this->readMessage($lastEntry);
        $toolUse = $message->toolCall;
        if (null !== $toolUse && (self::TOOL_ASK_USER !== $toolUse->name || ModelMessage::STOP_TOOL_USE !== $message->stopReason)) {
            $history[] = ['role' => 'user', 'content' => [$this->getToolResult($toolUse->id, self::INTERRUPTED_TOOL_RESULT, true)]];
        }
    }

    /**
     * Run a tool call of the model, other than ask_user, and return its result for the model along with the event to display, if any.
     */
    private function runTool(ToolCall $toolUse): array
    {
        return match ($toolUse->name) {
            self::TOOL_RUN_QUERY => $this->runQuery($toolUse->id, $toolUse->input),
            self::TOOL_SEARCH_PUBLIC_REFERENTIAL => null !== $this->referentialProvider
                ? $this->searchPublicReferential($this->referentialProvider, $toolUse->id, $toolUse->input)
                : [$this->getToolResult($toolUse->id, 'No public referential is available.', true), null],
            self::TOOL_DESCRIBE_TABLES => $this->describeTables($toolUse->id, $toolUse->input),
            self::TOOL_SEARCH_DOCUMENT_FIELDS => $this->searchDocumentFields($toolUse->id, $toolUse->input),
            default => [$this->getToolResult($toolUse->id, sprintf('Unknown tool "%s".', $toolUse->name), true), null],
        };
    }

    private function runQuery(string $toolUseId, array $input): array
    {
        $event = $this->getQueryEvent($input, self::MAX_DISPLAYED_ROWS) + ['toolUseId' => $toolUseId];
        if (null !== $event['error']) {
            // Only the error message goes back to the model: MySQL states what is wrong with the query without quoting any stored value
            return [$this->getToolResult($toolUseId, 'The query failed: '.$event['error'], true), $event];
        }

        return [$this->getToolResult($toolUseId, self::QUERY_SUCCESS_RESULT), $event];
    }

    /**
     * Run a query from the input of its run_query call and describe it for the display: its rows or its error, the answer sentence, the chart and the format to show it in first.
     */
    private function getQueryEvent(array $input, int $maxRows): array
    {
        $event = [
            'type' => self::EVENT_QUERY,
            'title' => (string) ($input['title'] ?? ''),
            'interpretation' => (string) ($input['interpretation'] ?? ''),
            'sql' => (string) ($input['sql'] ?? ''),
            'result' => null,
            'error' => null,
            'output' => null,
            'answer' => null,
            'chart' => null,
        ];

        try {
            $event['result'] = $this->queryManager->execute($event['sql'], $maxRows);
        } catch (\InvalidArgumentException|DBALException $exception) {
            $event['error'] = $exception->getMessage();

            return $event;
        }

        $event['answer'] = $this->getAnswer($input['answer_template'] ?? null, $event['result']);
        $event['chart'] = $this->getChart($input['chart'] ?? null, $event['result']['columns']);
        $event['output'] = $this->getOutput($input['output'] ?? null, $event['answer'], $event['chart']);

        return $event;
    }

    /**
     * Sentence answering the question, when the model gave a template for it and the result is a single value: the application fills the template, the model never knowing that value.
     */
    private function getAnswer(mixed $answerTemplate, array $result): ?string
    {
        if (!is_string($answerTemplate) || !str_contains($answerTemplate, self::ANSWER_VALUE_PLACEHOLDER) || 1 !== count($result['rows']) || 1 !== count($result['columns'])) {
            return null;
        }

        $value = reset($result['rows'][0]);
        if (null === $value || '' === (string) $value) {
            return null;
        }

        return str_replace(self::ANSWER_VALUE_PLACEHOLDER, (string) $value, $answerTemplate);
    }

    /**
     * Chart described by the model, kept only when its columns are actually part of the result, so that the display can rely on it.
     */
    private function getChart(mixed $chart, array $columns): ?array
    {
        if (!is_array($chart) || !in_array($chart['type'] ?? null, self::CHART_TYPES, true)) {
            return null;
        }

        $labelColumn = (string) ($chart['label_column'] ?? '');
        $valueColumns = array_values(array_unique(array_map('strval', (array) ($chart['value_columns'] ?? []))));
        if (!in_array($labelColumn, $columns, true) || [] === $valueColumns || [] !== array_diff($valueColumns, $columns)) {
            return null;
        }

        return ['type' => $chart['type'], 'labelColumn' => $labelColumn, 'valueColumns' => $valueColumns];
    }

    /**
     * Format to show the result in first: the one the model picked from the request, unless the result does not allow it (no single value for a text, no usable chart), the table otherwise.
     */
    private function getOutput(mixed $output, ?string $answer, ?array $chart): string
    {
        return match ($output) {
            self::OUTPUT_TEXT => null !== $answer ? self::OUTPUT_TEXT : self::OUTPUT_TABLE,
            self::OUTPUT_CHART => null !== $chart ? self::OUTPUT_CHART : self::OUTPUT_TABLE,
            self::OUTPUT_EXCEL => self::OUTPUT_EXCEL,
            default => self::OUTPUT_TABLE,
        };
    }

    private function searchPublicReferential(ReferentialProviderInterface $referentialProvider, string $toolUseId, array $input): array
    {
        try {
            $result = $referentialProvider->search((string) ($input['referential'] ?? ''), (string) ($input['text'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return [$this->getToolResult($toolUseId, $exception->getMessage(), true), null];
        }

        return [$this->getToolResult($toolUseId, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), null];
    }

    private function describeTables(string $toolUseId, array $input): array
    {
        $tableNames = array_values(array_unique(array_map('strval', (array) ($input['tables'] ?? []))));
        if ([] === $tableNames || count($tableNames) > self::MAX_DESCRIBED_TABLES) {
            return [$this->getToolResult($toolUseId, sprintf('Give between 1 and %d table names.', self::MAX_DESCRIBED_TABLES), true), null];
        }

        return [$this->getToolResult($toolUseId, $this->schemaManager->describeTables($tableNames)), null];
    }

    private function searchDocumentFields(string $toolUseId, array $input): array
    {
        try {
            $result = $this->catalogManager->search((string) ($input['text'] ?? ''));
        } catch (\InvalidArgumentException $exception) {
            return [$this->getToolResult($toolUseId, $exception->getMessage(), true), null];
        }

        return [$this->getToolResult($toolUseId, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), null];
    }

    private function getToolResult(string $toolUseId, string $content, bool $isError = false): array
    {
        $toolResult = ['type' => 'tool_result', 'toolUseID' => $toolUseId, 'content' => $content];
        if ($isError) {
            $toolResult['isError'] = true;
        }

        return $toolResult;
    }

    /**
     * Add the tokens of a model message to the usage of the turn, and their cost, from the prices per million tokens of the "models" configuration.
     */
    private function addUsage(array &$usage, ModelMessage $message, string $requestedModel): void
    {
        $inputTokens = $message->inputTokens;
        $outputTokens = $message->outputTokens;
        $cacheCreationInputTokens = $message->cacheCreationInputTokens;
        $cacheReadInputTokens = $message->cacheReadInputTokens;

        // A fallback model may have answered, whose prices are only known when it is listed in the "models" configuration: the requested model prices are the closest estimate otherwise
        $prices = $this->modelPrices[$message->model] ?? $this->modelPrices[$requestedModel]
            ?? throw new \LogicException(sprintf('The prices of the model "%s" are missing from the "models" configuration of the assistant.', $requestedModel));

        // The models that actually answered, a fallback one included
        $answeringModel = '' !== $message->model ? $message->model : $requestedModel;
        if (!in_array($answeringModel, $usage['models'], true)) {
            $usage['models'][] = $answeringModel;
        }

        $usage['inputTokens'] += $inputTokens;
        $usage['outputTokens'] += $outputTokens;
        $usage['cacheCreationInputTokens'] += $cacheCreationInputTokens;
        $usage['cacheReadInputTokens'] += $cacheReadInputTokens;
        $usage['cost'] += (
            $inputTokens * $prices['input']
            + $cacheCreationInputTokens * $prices['cacheWrite']
            + $cacheReadInputTokens * $prices['cacheRead']
            + $outputTokens * $prices['output']
        ) / 1000000;
    }

    /**
     * Tool definitions, in a fixed order and content so that they stay in the prompt cache. Strict mode guarantees that the inputs match their schema.
     */
    private function getToolDefinitions(): array
    {
        $jsonValueTable = $this->schemaManager->getJsonValueTableName();

        return [
            [
                'name' => self::TOOL_ASK_USER,
                'description' => 'Ask the user a question to clarify the request, when it involves a notion of the business dictionary that has no default rule, or when it is too broad. The conversation pauses until the user answers, by picking one of the options or by typing a free answer, and that answer comes back as the result of this tool. One question per call.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'The question, in the language of the user, short and without any technical term.'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Two to four short answers in the language of the user, the recommended one first.'],
                    ],
                    'required' => ['question', 'options'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => self::TOOL_RUN_QUERY,
                'description' => 'Run a single SELECT statement on the database, its curated views and its tables. The application displays its result to the user in the requested format, you never see it: the tool result only says whether the query ran, with the MySQL error message when it failed.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short title of the result, in the language of the user, shown above it.'],
                        'interpretation' => ['type' => 'string', 'description' => 'One sentence, in the language of the user, stating exactly what the query counts or lists: period, scope and every rule applied.'],
                        'sql' => ['type' => 'string', 'description' => 'The SELECT statement, in MySQL 8 syntax, naming every selected column (no SELECT * nor t.*, COUNT(*) apart).'],
                        'output' => ['type' => 'string', 'enum' => self::OUTPUTS, 'description' => 'Format to show the result in first, from what the user asked for: text for a single figure, table for a list or a breakdown, chart when a chart is asked for, excel when a file is asked for.'],
                        'answer_template' => [
                            'anyOf' => [['type' => 'string'], ['type' => 'null']],
                            'description' => 'When the query returns a single value: a sentence in the language of the user answering the question, where '.self::ANSWER_VALUE_PLACEHOLDER.' stands for that value and no other figure appears. Null otherwise.',
                        ],
                        'chart' => [
                            'anyOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => self::CHART_TYPES],
                                        'label_column' => ['type' => 'string', 'description' => 'Column of the result giving the labels, e.g. the year.'],
                                        'value_columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Numeric columns of the result to draw, a single one for a pie chart.'],
                                    ],
                                    'required' => ['type', 'label_column', 'value_columns'],
                                    'additionalProperties' => false,
                                ],
                                ['type' => 'null'],
                            ],
                            'description' => 'How to draw the result, whenever it lends itself to a chart (a label column and numeric columns), even when another output is asked for, so that the user can switch to it. Null otherwise.',
                        ],
                    ],
                    'required' => ['title', 'interpretation', 'sql', 'output', 'answer_template', 'chart'],
                    'additionalProperties' => false,
                ],
            ],
            ...(null !== $this->referentialProvider ? [[
                'name' => self::TOOL_SEARCH_PUBLIC_REFERENTIAL,
                'description' => trim('Search a public referential by name, to use the exact spelling of the database, or the id of a row, before filtering a query on it. '.$this->referentialProvider->getDescription()),
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'referential' => ['type' => 'string', 'enum' => array_values($this->referentialProvider->getReferentials())],
                        'text' => ['type' => 'string', 'description' => 'Part of the name to search.'],
                    ],
                    'required' => ['referential', 'text'],
                    'additionalProperties' => false,
                ],
            ]] : []),
            [
                'name' => self::TOOL_DESCRIBE_TABLES,
                'description' => 'Give the columns of database tables of the list of the database tables: their type, the table and column they reference, and the codes of the coded columns. Describe a table before querying it, rather than guessing its columns. At most '.self::MAX_DESCRIBED_TABLES.' tables per call.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Exact table names, e.g. ["order_item", "product"].'],
                    ],
                    'required' => ['tables'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => self::TOOL_SEARCH_DOCUMENT_FIELDS,
                'description' => 'Search the catalog of the fields of the JSON contents flattened in '.$jsonValueTable.' (forms typed online, stored API payloads...) by words of their path or of their label in the forms. The paths matching the most words come first, grouped by kind of document and template version (8 paths at most per group), followed by the labels of their keys: filter '.$jsonValueTable.'.generic_path on them. At most 25 paths are returned: search again with more precise words, e.g. words of a path found, when the result or a group is truncated.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'One to five words to look for in the paths and their labels, e.g. "median price".'],
                    ],
                    'required' => ['text'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
