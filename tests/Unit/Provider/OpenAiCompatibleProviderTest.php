<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;
use Tknoweb\AiSqlAssistantBundle\Provider\OpenAiCompatibleProvider;

/**
 * The provider of the APIs compatible with the OpenAI chat completions (OpenAI, Gemini, Mistral, Ollama...), on a simulated HTTP client.
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    private const TOOLS = [[
        'name' => 'run_query',
        'description' => 'Run a query.',
        'strict' => true,
        'inputSchema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']], 'required' => ['sql'], 'additionalProperties' => false],
    ]];

    private array $requests = [];
    private array $responses = [];

    public function testBuildsTheChatCompletionRequest(): void
    {
        $this->responses[] = $this->createCompletionResponse(['role' => 'assistant', 'content' => 'Hello'], 'stop');

        $this->createProvider('https://api.example.test/v1/', 'test-key')->createMessage('gpt-test', ['Instructions', 'Database'], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 16000);

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://api.example.test/v1/chat/completions', $this->requests[0]['url']);
        $this->assertContains('Authorization: Bearer test-key', $this->requests[0]['headers']);
        $this->assertSame([
            'model' => 'gpt-test',
            'messages' => [
                ['role' => 'system', 'content' => "Instructions\n\nDatabase"],
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'run_query',
                    'description' => 'Run a query.',
                    'parameters' => self::TOOLS[0]['inputSchema'],
                    'strict' => true,
                ],
            ]],
            'tool_choice' => 'auto',
            'parallel_tool_calls' => false,
            'max_completion_tokens' => 16000,
        ], $this->requests[0]['body']);
    }

    public function testAdaptsTheRequestToTheOptionsOfTheApi(): void
    {
        $this->responses[] = $this->createCompletionResponse(['role' => 'assistant', 'content' => 'Hello'], 'stop');

        // A local model: no key, "max_tokens", no strict schema, and a reasoning effort
        $this->createProvider('http://ollama.test:11434/v1', null, 'max_tokens', false, 'low')->createMessage('llama', ['Instructions'], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 1000);

        $body = $this->requests[0]['body'];
        $this->assertSame('http://ollama.test:11434/v1/chat/completions', $this->requests[0]['url']);
        $this->assertSame([], preg_grep('/^authorization:/i', $this->requests[0]['headers']));
        $this->assertSame(1000, $body['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('strict', $body['tools'][0]['function']);
        $this->assertSame('low', $body['reasoning_effort']);
    }

    public function testReadsTheAnswer(): void
    {
        $this->responses[] = $this->createCompletionResponse([
            'role' => 'assistant',
            'content' => 'I count.',
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'run_query', 'arguments' => '{"sql":"SELECT 1"}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'ask_user', 'arguments' => '{}']],
            ],
        ], 'tool_calls', ['prompt_tokens' => 1200, 'completion_tokens' => 15, 'prompt_tokens_details' => ['cached_tokens' => 1000]], 'gpt-test-2026');

        $message = $this->createProvider()->createMessage('gpt-test', [], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 1000);

        $this->assertSame(['I count.'], $message->texts);
        $this->assertSame(['call_1', 'run_query', ['sql' => 'SELECT 1']], [$message->toolCall->id, $message->toolCall->name, $message->toolCall->input]);
        $this->assertSame(ModelMessage::STOP_TOOL_USE, $message->stopReason);
        $this->assertSame('gpt-test-2026', $message->model);
        // The cached tokens are counted apart from the input tokens billed at the full price
        $this->assertSame([200, 15, 0, 1000], [$message->inputTokens, $message->outputTokens, $message->cacheCreationInputTokens, $message->cacheReadInputTokens]);
    }

    public function testReadsEveryShapeOfMessage(): void
    {
        $provider = $this->createProvider();
        $read = fn (array $message, ?string $finishReason = 'stop') => $provider->readMessage(['message' => $message, 'finish_reason' => $finishReason, 'model' => 'gpt-test', 'usage' => []]);

        $this->assertSame([], $read(['content' => null])->texts);
        $this->assertSame([], $read(['content' => '   '])->texts);
        $this->assertSame(['One', 'Two'], $read(['content' => [['type' => 'text', 'text' => 'One'], ['type' => 'image_url'], ['type' => 'text', 'text' => 'Two'], 'junk']])->texts);

        $this->assertSame(ModelMessage::STOP_END, $read(['content' => 'Done'])->stopReason);
        $this->assertSame(ModelMessage::STOP_MAX_TOKENS, $read(['content' => 'Half'], 'length')->stopReason);
        $this->assertSame(ModelMessage::STOP_REFUSAL, $read(['content' => null, 'refusal' => 'I cannot help.'])->stopReason);
        $this->assertSame(ModelMessage::STOP_REFUSAL, $read(['content' => null], 'content_filter')->stopReason);

        // Arguments given as an object by some APIs, or invalid JSON: the tool then reports what is missing
        $this->assertSame(['sql' => 'SELECT 1'], $read(['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'run_query', 'arguments' => ['sql' => 'SELECT 1']]]]])->toolCall->input);
        $this->assertSame([], $read(['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'run_query', 'arguments' => '{"sql": "SELECT']]]])->toolCall->input);
        $this->assertNull($read(['tool_calls' => [['function' => ['name' => 'run_query']]]])->toolCall, 'A tool call without id cannot get its result.');
        $this->assertSame(ModelMessage::STOP_TOOL_USE, $read(['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'run_query', 'arguments' => '{}']]]], 'stop')->stopReason);
    }

    public function testSendsTheHistoryBackWithItsThoughtSignature(): void
    {
        $toolCall = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'describe_tables', 'arguments' => '{"tables":["store"]}'], 'extra_content' => ['google' => ['thought_signature' => 'sig_g']]];
        $this->responses[] = $this->createCompletionResponse(['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall, ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'run_query', 'arguments' => '{}']]]], 'tool_calls');
        $provider = $this->createProvider();
        $first = $provider->createMessage('gemini-test', ['Instructions'], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 1000);

        $history = json_decode(json_encode([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'provider' => 'gemini', 'message' => $first->raw],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'call_1', 'content' => '## store']]],
            ['role' => 'assistant', 'provider' => 'gemini', 'message' => $first->raw],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'call_1', 'content' => 'The query failed: syntax error', 'isError' => true]]],
        ], JSON_THROW_ON_ERROR), true);
        $this->responses[] = $this->createCompletionResponse(['role' => 'assistant', 'content' => 'Done'], 'stop');
        $provider->createMessage('gemini-test', ['Instructions'], $history, self::TOOLS, 1000);

        $messages = $this->requests[1]['body']['messages'];
        $this->assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'tool'], array_column($messages, 'role'));
        // Only the first call, the one the assistant ran, with the signature Gemini requires back
        $this->assertSame(['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]], $messages[2]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '## store'], $messages[3]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Error: The query failed: syntax error'], $messages[5]);
    }

    public function testTurnsTheApiFailuresIntoAProviderException(): void
    {
        $failures = [
            'HTTP error' => new MockResponse('{"error": {"message": "invalid key"}}', ['http_code' => 401]),
            'server error' => new MockResponse('{"error": {"message": "overloaded"}}', ['http_code' => 503]),
            'invalid JSON' => new MockResponse('<html>Bad gateway</html>', ['http_code' => 200]),
            'transport error' => new MockResponse('', ['error' => 'Could not resolve host']),
            'no answer' => new MockResponse('{"choices": []}', ['http_code' => 200]),
        ];

        foreach ($failures as $failure => $response) {
            $this->responses[] = $response;
            try {
                $this->createProvider()->createMessage('gpt-test', [], [['role' => 'user', 'content' => 'Hi']], [], 1000);
                $this->fail(sprintf('The %s must end the turn.', $failure));
            } catch (ModelProviderException $exception) {
                $this->assertStringStartsWith('The model API ', $exception->getMessage(), $failure);
            }
        }
    }

    private function createProvider(string $baseUrl = 'https://api.example.test/v1', ?string $apiKey = 'test-key', string $maxTokensParameter = 'max_completion_tokens', bool $strictTools = true, ?string $reasoningEffort = null): OpenAiCompatibleProvider
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? 'null', true), 'headers' => $options['headers'] ?? []];

            return array_shift($this->responses) ?? $this->fail('The simulated API has no response left.');
        });

        return new OpenAiCompatibleProvider($httpClient, $baseUrl, $apiKey, $maxTokensParameter, $strictTools, $reasoningEffort, 30.0);
    }

    private function createCompletionResponse(array $message, string $finishReason, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5], string $model = 'gpt-test'): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [['index' => 0, 'finish_reason' => $finishReason, 'message' => $message]],
            'usage' => $usage,
        ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
