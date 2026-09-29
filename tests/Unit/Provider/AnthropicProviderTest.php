<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Provider;

use Anthropic\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tknoweb\AiSqlAssistantBundle\Provider\AnthropicProvider;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;

/**
 * The Claude provider, on the official SDK whose HTTP transport is simulated: the requests it builds, the messages it reads, and the history it sends back unchanged.
 */
class AnthropicProviderTest extends TestCase
{
    private const TOOLS = [[
        'name' => 'run_query',
        'description' => 'Run a query.',
        'strict' => true,
        'inputSchema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']], 'required' => ['sql'], 'additionalProperties' => false],
    ]];

    // Requests received by the simulated API, with their decoded body and their headers
    private array $requests = [];
    private array $responses = [];

    public function testBuildsTheRequestWithTheCacheTheThinkingAndTheFallback(): void
    {
        $this->responses[] = $this->createMessageResponse([['type' => 'text', 'text' => 'Hello']], 'end_turn');

        $this->createProvider('medium')->createMessage('claude-sonnet-5', ['Instructions', 'Database'], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 16000);

        $this->assertCount(1, $this->requests);
        $this->assertSame('/v1/messages', parse_url($this->requests[0]['url'], PHP_URL_PATH));
        $body = $this->requests[0]['body'];
        $this->assertSame('claude-sonnet-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertSame([['type' => 'text', 'text' => 'Instructions'], ['type' => 'text', 'text' => 'Database', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame(['effort' => 'medium'], $body['output_config']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['type' => 'auto', 'disable_parallel_tool_use' => true], $body['tool_choice']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $body['messages']);
        $this->assertSame([$this->sortKeys([
            'name' => 'run_query',
            'input_schema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']], 'required' => ['sql'], 'additionalProperties' => false],
            'description' => 'Run a query.',
            'strict' => true,
        ])], array_map(fn (array $tool) => $this->sortKeys(array_intersect_key($tool, array_flip(['name', 'input_schema', 'description', 'strict']))), $body['tools']));
        $this->assertSame('server-side-fallback-2026-07-01', $this->getHeader(0, 'anthropic-beta'));
        $this->assertSame('test-key', $this->getHeader(0, 'x-api-key'));
    }

    public function testLeavesOutTheOptionsTurnedOff(): void
    {
        $this->responses[] = $this->createMessageResponse([['type' => 'text', 'text' => 'Hello']], 'end_turn');

        $this->createProvider(null, false, false)->createMessage('claude-sonnet-5', [], [['role' => 'user', 'content' => 'Hi']], [], 1000);

        $body = $this->requests[0]['body'];
        foreach (['thinking', 'output_config', 'fallbacks', 'system'] as $key) {
            $this->assertArrayNotHasKey($key, array_filter($body, fn (mixed $value) => [] !== $value), $key);
        }
        $this->assertNull($this->getHeader(0, 'anthropic-beta'));
    }

    public function testReadsTheTextsTheFirstToolCallTheStopReasonAndTheUsage(): void
    {
        $this->responses[] = $this->createMessageResponse([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'signature_1'],
            ['type' => 'text', 'text' => 'I count.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'run_query', 'input' => ['sql' => 'SELECT 1']],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ask_user', 'input' => ['question' => 'Why?']],
        ], 'tool_use', ['input_tokens' => 100, 'output_tokens' => 20, 'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 500], 'claude-opus-5');

        $message = $this->createProvider()->createMessage('claude-sonnet-5', ['Instructions'], [['role' => 'user', 'content' => 'Hi']], self::TOOLS, 1000);

        $this->assertSame(['I count.'], $message->texts);
        $this->assertSame('toolu_1', $message->toolCall->id);
        $this->assertSame('run_query', $message->toolCall->name);
        $this->assertSame(['sql' => 'SELECT 1'], $message->toolCall->input);
        $this->assertSame(ModelMessage::STOP_TOOL_USE, $message->stopReason);
        $this->assertSame('claude-opus-5', $message->model, 'The model that answered, a fallback one possibly.');
        $this->assertSame([100, 20, 1000, 500], [$message->inputTokens, $message->outputTokens, $message->cacheCreationInputTokens, $message->cacheReadInputTokens]);

        // The raw message is read back the same way
        $readMessage = $this->createProvider()->readMessage(json_decode(json_encode($message->raw), true));
        $this->assertEquals($message->toolCall, $readMessage->toolCall);
        $this->assertSame($message->texts, $readMessage->texts);
        $this->assertSame($message->stopReason, $readMessage->stopReason);
    }

    public function testMapsTheStopReasons(): void
    {
        $stopReasons = [
            'end_turn' => ModelMessage::STOP_END,
            'stop_sequence' => ModelMessage::STOP_END,
            'refusal' => ModelMessage::STOP_REFUSAL,
            'max_tokens' => ModelMessage::STOP_MAX_TOKENS,
            // A tool use stop without any tool call has nothing to run
            'tool_use' => ModelMessage::STOP_END,
        ];

        foreach ($stopReasons as $apiStopReason => $expectedStopReason) {
            $this->responses[] = $this->createMessageResponse([['type' => 'text', 'text' => 'Text']], $apiStopReason);
            $message = $this->createProvider()->createMessage('claude-sonnet-5', [], [['role' => 'user', 'content' => 'Hi']], [], 1000);
            $this->assertSame($expectedStopReason, $message->stopReason, $apiStopReason);
        }
    }

    public function testSendsTheHistoryBackUnchanged(): void
    {
        $provider = $this->createProvider();
        $this->responses[] = $this->createMessageResponse([
            ['type' => 'thinking', 'thinking' => 'Let me think.', 'signature' => 'signature_1'],
            ['type' => 'tool_use', 'id' => 'toolu_q', 'name' => 'ask_user', 'input' => ['question' => 'Which year?', 'options' => ['2025']]],
        ], 'tool_use');
        $first = $provider->createMessage('claude-sonnet-5', ['Instructions'], [['role' => 'user', 'content' => 'How many?']], self::TOOLS, 1000);

        // The history as AssistantManager stores it, through a JSON column
        $history = json_decode(json_encode([
            ['role' => 'user', 'content' => 'How many?'],
            ['role' => 'assistant', 'provider' => 'anthropic', 'message' => $first->raw],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'toolu_q', 'content' => '2025']]],
            ['role' => 'assistant', 'provider' => 'anthropic', 'message' => $first->raw],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'toolu_q', 'content' => 'The query failed: syntax error', 'isError' => true]]],
        ], JSON_THROW_ON_ERROR), true);
        $this->responses[] = $this->createMessageResponse([['type' => 'text', 'text' => 'Done']], 'end_turn');
        $provider->createMessage('claude-sonnet-5', ['Instructions'], $history, self::TOOLS, 1000);

        $messages = $this->requests[1]['body']['messages'];
        $this->assertSame($this->sortKeys(['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'thinking' => 'Let me think.', 'signature' => 'signature_1'],
            ['type' => 'tool_use', 'id' => 'toolu_q', 'name' => 'ask_user', 'input' => ['question' => 'Which year?', 'options' => ['2025']]],
        ]]), $this->sortKeys($messages[1]));
        $this->assertSame($this->sortKeys(['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_q', 'content' => '2025']]]), $this->sortKeys($messages[2]));
        $this->assertSame($this->sortKeys(['type' => 'tool_result', 'tool_use_id' => 'toolu_q', 'content' => 'The query failed: syntax error', 'is_error' => true]), $this->sortKeys($messages[4]['content'][0]));
    }

    public function testTurnsAnApiErrorIntoAProviderException(): void
    {
        $this->responses[] = new MockResponse(json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]), [
            'http_code' => 401,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        try {
            $this->createProvider()->createMessage('claude-sonnet-5', [], [['role' => 'user', 'content' => 'Hi']], [], 1000);
            $this->fail('An API error must end the turn.');
        } catch (ModelProviderException $exception) {
            $this->assertStringStartsWith('The Claude API failed: ', $exception->getMessage());
        }
    }

    public function testTurnsAConnectionErrorIntoAProviderException(): void
    {
        $this->responses[] = new MockResponse('', ['error' => 'Could not resolve host']);

        $this->expectException(ModelProviderException::class);
        $this->createProvider()->createMessage('claude-sonnet-5', [], [['role' => 'user', 'content' => 'Hi']], [], 1000);
    }

    private function createProvider(?string $effort = 'medium', bool $thinking = true, bool $serverFallback = true): AnthropicProvider
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->requests[] = ['url' => $url, 'body' => json_decode($options['body'] ?? 'null', true), 'headers' => $options['headers'] ?? []];

            $response = array_shift($this->responses) ?? $this->fail('The simulated API has no response left.');
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        });

        return new AnthropicProvider(new Client(apiKey: 'test-key', requestOptions: ['transporter' => new Psr18Client($httpClient), 'maxRetries' => 0]), $effort, $thinking, $serverFallback);
    }

    private function createMessageResponse(array $content, string $stopReason, array $usage = ['input_tokens' => 10, 'output_tokens' => 5], string $model = 'claude-sonnet-5'): MockResponse
    {
        return new MockResponse(json_encode([
            'id' => 'msg_'.count($this->responses),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'stop_details' => null,
            'container' => null,
            'context_management' => null,
            'usage' => $usage + ['cache_creation_input_tokens' => null, 'cache_read_input_tokens' => null],
        ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function getHeader(int $requestIndex, string $name): ?string
    {
        foreach ($this->requests[$requestIndex]['headers'] as $header) {
            if (0 === stripos($header, $name.':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * The value with the keys of its objects sorted, at every depth, the SDK not keeping the order of the keys it serializes.
     */
    private function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => is_array($item) ? $this->sortKeys($item) : $item, $value);
    }
}
