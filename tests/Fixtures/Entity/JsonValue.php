<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractJsonValue;

#[ORM\Entity]
#[ORM\Table(name: 'json_value')]
#[ORM\Index(columns: ['source_table', 'source_column', 'generic_path'])]
#[ORM\Index(columns: ['source_table', 'source_id'])]
class JsonValue extends AbstractJsonValue
{
}
