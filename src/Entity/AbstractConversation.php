<?php

namespace Tknoweb\AiSqlAssistantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationInterface;

/**
 * Conversation of a user with the assistant. The history is the one AssistantManager returns, stored as is since the model messages must be sent back unchanged, and it also records which
 * query produced which result. The results themselves are never stored: displaying a conversation again runs its queries again.
 * The application extends it with its entity, which adds the owner relation, the archiving and the table name.
 */
#[ORM\MappedSuperclass]
abstract class AbstractConversation implements ConversationInterface
{
    public const TITLE_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    // The first question of the user, shortened
    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TITLE_MAX_LENGTH)]
    protected string $title;

    // Key of the model in the "models" configuration of the bundle, set when the conversation starts and kept until its end
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    protected string $modelKey;

    #[ORM\Column(type: 'json')]
    protected array $history = [];

    #[ORM\Column]
    protected int $inputTokens = 0;

    #[ORM\Column]
    protected int $outputTokens = 0;

    #[ORM\Column]
    protected int $cacheCreationInputTokens = 0;

    #[ORM\Column]
    protected int $cacheReadInputTokens = 0;

    // Cost of the whole conversation in dollars, computed from the token prices of the "models" configuration
    #[ORM\Column]
    protected float $cost = 0.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getModelKey(): string
    {
        return $this->modelKey;
    }

    public function setModelKey(string $modelKey): static
    {
        $this->modelKey = $modelKey;

        return $this;
    }

    public function getHistory(): array
    {
        return $this->history;
    }

    public function setHistory(array $history): static
    {
        $this->history = $history;

        return $this;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationInputTokens(): int
    {
        return $this->cacheCreationInputTokens;
    }

    public function getCacheReadInputTokens(): int
    {
        return $this->cacheReadInputTokens;
    }

    public function getCost(): float
    {
        return $this->cost;
    }

    public function addUsage(array $usage): static
    {
        $this->inputTokens += $usage['inputTokens'];
        $this->outputTokens += $usage['outputTokens'];
        $this->cacheCreationInputTokens += $usage['cacheCreationInputTokens'];
        $this->cacheReadInputTokens += $usage['cacheReadInputTokens'];
        $this->cost += $usage['cost'];

        return $this;
    }
}
