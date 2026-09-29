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
     * Remove the conversation from the view of its owner, while keeping it in the database along with its logged exchanges.
     */
    public function archive(): void;

    public function isArchived(): bool;
}
