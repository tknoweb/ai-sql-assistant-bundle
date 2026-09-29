<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Sensitive;

/**
 * Employee of a store, with a password and a recovery code the model must never read.
 */
#[ORM\Entity]
#[ORM\Table(name: 'employee')]
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[Sensitive]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recoveryCode = null;

    #[ORM\ManyToOne(targetEntity: Store::class)]
    private ?Store $store = null;

    // Keys written by code, flattened without any vocabulary
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $profile = null;
}
