<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;

/**
 * An AI model API the assistant can run on: the bundle ships one for Anthropic and one for the APIs compatible with the OpenAI chat completions (OpenAI, Gemini, Mistral, a local model served
 * by Ollama...), and an application can plug its own through the "service" provider type.
 * The history is the one of AssistantManager: user texts ("content" is a string), tool results ("content" is a list of ["type" => "tool_result", "toolUseID", "content", "isError"]) and
 * the raw messages this provider returned ("message"), which it must be able to send back as they were, since some models require their reasoning to be replayed unchanged.
 */
interface ModelProviderInterface
{
    /**
     * Send the conversation to the model and return its answer. $systemTexts are the blocks of the system prompt, identical from one request to the next so that the API can cache them.
     * $tools are the tool definitions: "name", "description", "inputSchema" (a JSON schema) and "strict". The model must call one tool at most per answer, and a failure of the API must end
     * the turn with a ModelProviderException, the only exception AssistantManager expects.
     */
    public function createMessage(string $model, array $systemTexts, array $history, array $tools, int $maxTokens): ModelMessage;

    /**
     * Neutral reading of a raw message this provider returned, as stored in the history.
     */
    public function readMessage(array $raw): ModelMessage;
}
