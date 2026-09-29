<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures;

use Tknoweb\AiSqlAssistantBundle\Contract\ModelProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Provider\ToolCall;

/**
 * Model provider answering from a script instead of an API: each call returns the next queued answer, or throws the next queued exception, and is recorded with what it was sent.
 * Its raw messages are plain arrays built by the static helpers, read back as they are.
 */
class ScriptedModelProvider implements ModelProviderInterface
{
    // Calls received, each one with its "model", "systemTexts", "history", "tools" and "maxTokens"
    public array $calls = [];

    private array $answers = [];

    public static function text(string $text, string $model = TestKernel::MODEL_ID, array $usage = []): array
    {
        return self::answer([$text], null, ModelMessage::STOP_END, $model, $usage);
    }

    public static function toolCall(string $id, string $name, array $input, ?string $text = null, string $model = TestKernel::MODEL_ID, array $usage = []): array
    {
        return self::answer(null !== $text ? [$text] : [], ['id' => $id, 'name' => $name, 'input' => $input], ModelMessage::STOP_TOOL_USE, $model, $usage);
    }

    public static function question(string $id, string $question, array $options): array
    {
        return self::toolCall($id, 'ask_user', ['question' => $question, 'options' => $options]);
    }

    public static function query(string $id, string $sql, array $input = []): array
    {
        return self::toolCall($id, 'run_query', $input + [
            'title' => 'Result',
            'interpretation' => 'I counted what was asked.',
            'sql' => $sql,
            'output' => 'table',
            'answer_template' => null,
            'chart' => null,
        ]);
    }

    public static function answer(array $texts, ?array $toolCall, string $stopReason, string $model = TestKernel::MODEL_ID, array $usage = []): array
    {
        return [
            'texts' => $texts,
            'toolCall' => $toolCall,
            'stopReason' => $stopReason,
            'model' => $model,
            'usage' => $usage + ['input' => 0, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0],
        ];
    }

    public function queue(array|\Throwable ...$answers): static
    {
        array_push($this->answers, ...$answers);

        return $this;
    }

    public function getPendingAnswerCount(): int
    {
        return count($this->answers);
    }

    public function createMessage(string $model, array $systemTexts, array $history, array $tools, int $maxTokens): ModelMessage
    {
        $this->calls[] = ['model' => $model, 'systemTexts' => $systemTexts, 'history' => $history, 'tools' => $tools, 'maxTokens' => $maxTokens];

        $answer = array_shift($this->answers) ?? throw new \LogicException('The scripted model provider has no answer left.');
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $this->readMessage($answer);
    }

    public function readMessage(array $raw): ModelMessage
    {
        return new ModelMessage(
            raw: $raw,
            model: $raw['model'],
            texts: $raw['texts'],
            toolCall: null !== $raw['toolCall'] ? new ToolCall($raw['toolCall']['id'], $raw['toolCall']['name'], $raw['toolCall']['input']) : null,
            stopReason: $raw['stopReason'],
            inputTokens: $raw['usage']['input'],
            outputTokens: $raw['usage']['output'],
            cacheCreationInputTokens: $raw['usage']['cacheWrite'],
            cacheReadInputTokens: $raw['usage']['cacheRead'],
        );
    }
}
