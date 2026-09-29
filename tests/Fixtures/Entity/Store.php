<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\StoreStatus;

/**
 * Store of the retail chain.
 *
 * This second paragraph is left out of the description.
 */
#[ORM\Entity]
#[ORM\Table(name: 'store')]
class Store
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20, enumType: StoreStatus::class)]
    private StoreStatus $status;

    #[ORM\Column(type: 'simple_array', nullable: true)]
    private ?array $tags = null;

    #[ORM\ManyToMany(targetEntity: Region::class)]
    #[ORM\JoinTable(name: 'store_region')]
    private Collection $regions;

    public function __construct(string $name, StoreStatus $status = StoreStatus::Open)
    {
        $this->name = $name;
        $this->status = $status;
        $this->regions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
