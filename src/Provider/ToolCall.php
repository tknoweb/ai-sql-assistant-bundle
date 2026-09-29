<?php

namespace Tknoweb\AiSqlAssistantBundle\Provider;

/**
 * Call of a tool by the model, whatever its provider.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {
    }
}
