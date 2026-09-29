<?php

namespace Tknoweb\AiSqlAssistantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Contract\ConversationExchangeInterface;

/**
 * Log of one turn of a conversation: what the user typed, what the assistant did in return and what it cost. It outlives the conversation when its owner archives it.
 * The response never holds any database value: the query results and the answer sentences filled with them are left out, only the queries themselves are kept.
 * The application extends it with its entity, which adds the conversation relation, the creation date and the table name.
 */
#[ORM\MappedSuperclass]
abstract class AbstractConversationExchange implements ConversationExchangeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(type: 'text')]
    protected string $userInput;

    // Whether the user input answered a question of the assistant rather than starting a new request
    #[ORM\Column]
    protected bool $answeredQuestion = false;

    // Events of the turn as returned by AssistantManager::continueConversation(), without the query results
    #[ORM\Column(type: 'json')]
    protected array $response = [];

    // Models that actually answered, comma separated: a fallback model may have taken over from the requested one
    #[ORM\Column(length: 255)]
    protected string $models = '';

    #[ORM\Column]
    protected int $inputTokens = 0;

    #[ORM\Column]
    protected int $outputTokens = 0;

    #[ORM\Column]
    protected int $cacheCreationInputTokens = 0;

    #[ORM\Column]
    protected int $cacheReadInputTokens = 0;

    // Cost of the turn in dollars
    #[ORM\Column]
    protected float $cost = 0.0;

    public function __toString(): string
    {
        return $this->getConversation()->getTitle();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserInput(): string
    {
        return $this->userInput;
    }

    public function setUserInput(string $userInput): static
    {
        $this->userInput = $userInput;

        return $this;
    }

    public function isAnsweredQuestion(): bool
    {
        return $this->answeredQuestion;
    }

    public function setAnsweredQuestion(bool $answeredQuestion): static
    {
        $this->answeredQuestion = $answeredQuestion;

        return $this;
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    public function setResponse(array $response): static
    {
        $this->response = $response;

        return $this;
    }

    public function getModels(): string
    {
        return $this->models;
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

    public function setUsage(array $usage): static
    {
        $this->models = implode(', ', $usage['models']);
        $this->inputTokens = $usage['inputTokens'];
        $this->outputTokens = $usage['outputTokens'];
        $this->cacheCreationInputTokens = $usage['cacheCreationInputTokens'];
        $this->cacheReadInputTokens = $usage['cacheReadInputTokens'];
        $this->cost = $usage['cost'];

        return $this;
    }
}
