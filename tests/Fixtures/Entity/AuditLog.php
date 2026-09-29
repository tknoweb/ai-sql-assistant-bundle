<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

// Readable, but its JSON column is not flattened (the "unflattened_json_entities" configuration of the test application)
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;
}
