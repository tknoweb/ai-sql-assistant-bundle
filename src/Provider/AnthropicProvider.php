<?php

namespace Tknoweb\AiSqlAssistantBundle\Provider;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Tknoweb\AiSqlAssistantBundle\Contract\ModelProviderInterface;

/**
 * Claude models, through the beta messages API of the official Anthropic SDK: adaptive thinking, the server-side fallback that answers with another model when the requested one declines a
 * request, and the prompt cache, with a breakpoint closing the system prompt and another one following the conversation.
 * The messages are stored in their API form and rebuilt as SDK objects, so that they are sent back exactly as received, thinking blocks and their signature included.
 */
class AnthropicProvider implements ModelProviderInterface
{
    // Lets the API answer with a fallback model when the requested one declines a request
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * $effort is the "effort" of the output configuration ("low", "medium", "high"), null for the default of the API.
     */
    public function __construct(
        private readonly Client $client,
        private readonly ?string $effort = null,
        private readonly bool $thinking = true,
        private readonly bool $serverFallback = true,
    ) {
    }

    public function createMessage(string $model, array $systemTexts, array $history, array $tools, int $maxTokens): ModelMessage
    {
        $systemBlocks = array_map(fn (string $text) => ['type' => 'text', 'text' => $text], array_values($systemTexts));
        if ([] !== $systemBlocks) {
            // The whole prefix, tools included, is then read from the prompt cache instead of being billed at the full input price
            $systemBlocks[array_key_last($systemBlocks)]['cacheControl'] = ['type' => 'ephemeral'];
        }

        $parameters = [
            'maxTokens' => $maxTokens,
            'messages' => $this->getApiMessages($history),
            'model' => $model,
            // Caches the conversation as well, which every call of a turn sends again
            'cacheControl' => ['type' => 'ephemeral'],
            'system' => $systemBlocks,
            // One tool call per answer at most, so that a question of the model is never mixed with other calls waiting for their result
            'toolChoice' => ['type' => 'auto', 'disableParallelToolUse' => true],
            'tools' => array_map(fn (array $tool) => array_intersect_key($tool, array_flip(['name', 'description', 'inputSchema', 'strict'])), $tools),
        ];
        if (null !== $this->effort) {
            $parameters['outputConfig'] = ['effort' => $this->effort];
        }
        if ($this->thinking) {
            $parameters['thinking'] = ['type' => 'adaptive'];
        }
        if ($this->serverFallback) {
            $parameters['fallbacks'] = 'default';
            $parameters['betas'] = [self::FALLBACK_BETA];
        }

        try {
            $message = $this->client->beta->messages->create(...$parameters);
        } catch (AnthropicException $exception) {
            throw new ModelProviderException('The Claude API failed: '.$exception->getMessage(), previous: $exception);
        }

        return $this->getModelMessage($message);
    }

    public function readMessage(array $raw): ModelMessage
    {
        return $this->getModelMessage(BetaMessage::fromArray($raw));
    }

    private function getModelMessage(BetaMessage $message): ModelMessage
    {
        $texts = [];
        $toolCall = null;
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $texts[] = $block->text;
            } elseif ($block instanceof BetaToolUseBlock && null === $toolCall) {
                $toolCall = new ToolCall($block->id, $block->name, (array) $block->input);
            }
        }

        return new ModelMessage(
            raw: $message->jsonSerialize(),
            model: $message->model,
            texts: $texts,
            toolCall: $toolCall,
            stopReason: match ($message->stopReason) {
                'tool_use' => null !== $toolCall ? ModelMessage::STOP_TOOL_USE : ModelMessage::STOP_END,
                'refusal' => ModelMessage::STOP_REFUSAL,
                'max_tokens' => ModelMessage::STOP_MAX_TOKENS,
                default => ModelMessage::STOP_END,
            },
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheCreationInputTokens: $message->usage->cacheCreationInputTokens ?? 0,
            cacheReadInputTokens: $message->usage->cacheReadInputTokens ?? 0,
        );
    }

    private function getApiMessages(array $history): array
    {
        return array_map(
            fn (array $entry) => 'assistant' === $entry['role']
                ? ['role' => 'assistant', 'content' => BetaMessage::fromArray($entry['message'])->content]
                : ['role' => 'user', 'content' => $entry['content']],
            $history
        );
    }
}
