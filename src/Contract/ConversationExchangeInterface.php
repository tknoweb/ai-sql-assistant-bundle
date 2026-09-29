<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

/**
 * Log of one turn of a conversation. AbstractConversationExchange implements everything but its conversation and its creation date, which follow the conventions of the application.
 */
interface ConversationExchangeInterface
{
    public function getId(): ?int;

    public function getConversation(): ConversationInterface;

    public function setConversation(ConversationInterface $conversation): static;

    public function getCreatedAt(): ?\DateTimeInterface;

    public function getUserInput(): string;

    public function setUserInput(string $userInput): static;

    public function isAnsweredQuestion(): bool;

    public function setAnsweredQuestion(bool $answeredQuestion): static;

    public function getResponse(): array;

    public function setResponse(array $response): static;

    public function getModels(): string;

    public function getInputTokens(): int;

    public function getOutputTokens(): int;

    public function getCacheCreationInputTokens(): int;

    public function getCacheReadInputTokens(): int;

    public function getCost(): float;

    /**
     * Duration of the turn in milliseconds, null for an exchange logged before it was measured.
     */
    public function getDuration(): ?int;

    public function setDuration(?int $duration): static;

    /**
     * Set the models, tokens and cost of the turn, as returned in the "usage" of AssistantManager::continueConversation().
     */
    public function setUsage(array $usage): static;
}
