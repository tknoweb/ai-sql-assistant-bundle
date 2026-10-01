<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractJsonValue;

#[ORM\Entity]
#[ORM\Table(name: 'json_value_data')]
#[ORM\Index(columns: ['json_path_id', 'source_id'])]
#[ORM\Index(columns: ['source_id'])]
class JsonValue extends AbstractJsonValue
{
}
