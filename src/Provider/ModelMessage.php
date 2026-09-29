<?php

namespace Tknoweb\AiSqlAssistantBundle\Provider;

/**
 * Answer of a model, read the same way whatever its provider: its texts, the tool it calls if any, why it stopped and the tokens it used. The raw message is what the provider stores in the
 * history and sends back on the next calls.
 */
final class ModelMessage
{
    // The model calls a tool and waits for its result
    public const STOP_TOOL_USE = 'tool_use';
    public const STOP_END = 'end';
    public const STOP_REFUSAL = 'refusal';
    public const STOP_MAX_TOKENS = 'max_tokens';

    public function __construct(
        public readonly array $raw,
        // Model that actually answered, a fallback one possibly
        public readonly string $model,
        public readonly array $texts,
        public readonly ?ToolCall $toolCall,
        public readonly string $stopReason,
        // Input tokens billed at the full price, the cached ones being counted apart
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheCreationInputTokens = 0,
        public readonly int $cacheReadInputTokens = 0,
    ) {
    }
}
