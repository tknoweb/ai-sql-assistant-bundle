<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Conversation of a user with the assistant, as the application stores it. AbstractConversation implements everything but its owner and its archiving, which follow the conventions of the
 * application (its user entity, its soft delete).
 */
interface ConversationInterface
{
    public function getId(): ?int;

    public function getOwner(): UserInterface;

    public function setOwner(UserInterface $owner): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getModelKey(): string;

    public function setModelKey(string $modelKey): static;

    public function getHistory(): array;

    public function setHistory(array $history): static;

    /**
     * Add the usage of a turn, as returned by AssistantManager::continueConversation(), to the tokens and cost of the conversation.
     */
    public function addUsage(array $usage): static;

    public function getCost(): float;

    /**
     * Message of the user whose turn has not ended: waiting for the request that runs it, running, or failed (see ConversationManager::getTurnState()).
     */
    public function getPendingInput(): ?string;

    public function getTurnStartedAt(): ?\DateTimeImmutable;

    /**
     * Steps the pending turn went through, as reported by AssistantManager::continueConversation().
     */
    public function getTurnSteps(): array;

    /**
     * Make $userInput the pending turn, waiting for the request that runs it, in place of a failed one.
     */
    public function requestTurn(string $userInput): static;

    /**
     * Record that a request started running the pending turn.
     */
    public function startTurn(): static;

    public function addTurnStep(array $step): static;

    /**
     * Forget the pending turn, once its history is saved.
     */
    public function endTurn(): static;

    /**
     * Remove the conversation from the view of its owner, while keeping it in the database along with its logged exchanges.
     */
    public function archive(): void;

    public function isArchived(): bool;
}
