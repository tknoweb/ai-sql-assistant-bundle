<?php

namespace Tknoweb\AiSqlAssistantBundle\Provider;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ModelProviderInterface;

/**
 * Models served by an API compatible with the chat completions of OpenAI: OpenAI itself, Gemini through its OpenAI compatible endpoint, Mistral, or a local model served by Ollama or vLLM.
 * The API caches the prompt by itself when it does. A raw message holds the message of the answer, its finish reason, its model and its usage, the message being sent back as received
 * (the thought signature Gemini adds to a tool call included), with only its first tool call: the assistant runs a single call per answer.
 */
class OpenAiCompatibleProvider implements ModelProviderInterface
{
    // Prefix of the result of a failed tool call, the API having no error flag
    private const TOOL_ERROR_PREFIX = 'Error: ';

    /**
     * $baseUrl is the base of the API, e.g. "https://api.openai.com/v1", $maxTokensParameter the name of the output limit ("max_completion_tokens" for OpenAI, "max_tokens" for most
     * other APIs), $strictTools whether the API supports strict tool schemas, and $reasoningEffort the "reasoning_effort" of the reasoning models, null to leave it out.
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly string $maxTokensParameter = 'max_completion_tokens',
        private readonly bool $strictTools = true,
        private readonly ?string $reasoningEffort = null,
        private readonly float $timeout = 120.0,
    ) {
    }

    public function createMessage(string $model, array $systemTexts, array $history, array $tools, int $maxTokens): ModelMessage
    {
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => implode("\n\n", $systemTexts)],
                ...$this->getApiMessages($history),
            ],
            'tools' => array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['inputSchema'],
                ] + ($this->strictTools ? ['strict' => (bool) ($tool['strict'] ?? false)] : []),
            ], $tools),
            'tool_choice' => 'auto',
            'parallel_tool_calls' => false,
            $this->maxTokensParameter => $maxTokens,
        ];
        if (null !== $this->reasoningEffort) {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        try {
            $data = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/chat/completions', [
                'json' => $body,
                'headers' => null !== $this->apiKey && '' !== $this->apiKey ? ['Authorization' => 'Bearer '.$this->apiKey] : [],
                'timeout' => $this->timeout,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new ModelProviderException('The model API failed: '.$exception->getMessage(), previous: $exception);
        }

        $choice = $data['choices'][0] ?? throw new ModelProviderException('The model API returned no answer.');

        return $this->readMessage([
            'message' => $choice['message'] ?? [],
            'finish_reason' => $choice['finish_reason'] ?? null,
            'model' => (string) ($data['model'] ?? $model),
            'usage' => $data['usage'] ?? [],
        ]);
    }

    public function readMessage(array $raw): ModelMessage
    {
        $message = $raw['message'] ?? [];
        $toolCallData = $message['tool_calls'][0] ?? null;
        $toolCall = is_array($toolCallData) && isset($toolCallData['id'], $toolCallData['function']['name'])
            ? new ToolCall((string) $toolCallData['id'], (string) $toolCallData['function']['name'], $this->getArguments($toolCallData['function']['arguments'] ?? null))
            : null;

        $usage = $raw['usage'] ?? [];
        $cachedTokens = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new ModelMessage(
            raw: $raw,
            model: (string) ($raw['model'] ?? ''),
            texts: $this->getTexts($message['content'] ?? null),
            toolCall: $toolCall,
            stopReason: match (true) {
                !empty($message['refusal']) || 'content_filter' === ($raw['finish_reason'] ?? null) => ModelMessage::STOP_REFUSAL,
                null !== $toolCall => ModelMessage::STOP_TOOL_USE,
                'length' === ($raw['finish_reason'] ?? null) => ModelMessage::STOP_MAX_TOKENS,
                default => ModelMessage::STOP_END,
            },
            inputTokens: max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cachedTokens),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            cacheReadInputTokens: $cachedTokens,
        );
    }

    private function getApiMessages(array $history): array
    {
        $messages = [];
        foreach ($history as $entry) {
            if ('assistant' === $entry['role']) {
                $message = $entry['message']['message'] ?? [];
                $apiMessage = ['role' => 'assistant', 'content' => $message['content'] ?? null];
                if (isset($message['tool_calls'][0])) {
                    $apiMessage['tool_calls'] = [$message['tool_calls'][0]];
                }
                $messages[] = $apiMessage;
            } elseif (is_string($entry['content'])) {
                $messages[] = ['role' => 'user', 'content' => $entry['content']];
            } else {
                foreach ($entry['content'] as $toolResult) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolResult['toolUseID'],
                        'content' => (($toolResult['isError'] ?? false) ? self::TOOL_ERROR_PREFIX : '').$toolResult['content'],
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Texts of a message, whose content is a string, or a list of parts for some APIs.
     */
    private function getTexts(mixed $content): array
    {
        if (is_string($content)) {
            return '' !== trim($content) ? [$content] : [];
        }

        $texts = [];
        foreach (is_array($content) ? $content : [] as $part) {
            if (is_array($part) && 'text' === ($part['type'] ?? null) && is_string($part['text'] ?? null) && '' !== trim($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        return $texts;
    }

    /**
     * Arguments of a tool call, a JSON object as a string, or an empty input when the model wrote an invalid one: the tool then reports what is missing.
     */
    private function getArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        $decoded = is_string($arguments) ? json_decode($arguments, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
