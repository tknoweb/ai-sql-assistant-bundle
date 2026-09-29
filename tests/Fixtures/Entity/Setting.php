<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Setting identified by its name: its JSON column is not flattened, the flattening reading the rows by increasing "id".
 */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $value = null;
}
