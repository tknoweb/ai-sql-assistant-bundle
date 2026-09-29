<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationInterface;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractConversationExchange;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Repository\ConversationExchangeRepository;

#[ORM\Entity(repositoryClass: ConversationExchangeRepository::class)]
#[ORM\Table(name: 'conversation_exchange')]
class ConversationExchange extends AbstractConversationExchange
{
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Conversation $conversation;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getConversation(): ConversationInterface
    {
        return $this->conversation;
    }

    public function setConversation(ConversationInterface $conversation): static
    {
        if (!$conversation instanceof Conversation) {
            throw new \InvalidArgumentException('An exchange of the test application belongs to one of its conversations.');
        }

        $this->conversation = $conversation;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
