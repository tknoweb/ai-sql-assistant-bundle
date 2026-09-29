<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractConversation;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Repository\ConversationRepository;

/**
 * Conversation of the test application, whose users live in memory: the owner is kept by identifier.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
class Conversation extends AbstractConversation
{
    #[ORM\Column(length: 180)]
    private string $ownerIdentifier;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function getOwner(): UserInterface
    {
        return new InMemoryUser($this->ownerIdentifier, null);
    }

    public function setOwner(UserInterface $owner): static
    {
        $this->ownerIdentifier = $owner->getUserIdentifier();

        return $this;
    }

    public function archive(): void
    {
        $this->archivedAt = new \DateTimeImmutable();
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }
}
