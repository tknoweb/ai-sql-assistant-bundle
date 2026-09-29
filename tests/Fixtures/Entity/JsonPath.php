<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractJsonPath;

#[ORM\Entity]
#[ORM\Table(name: 'json_path')]
#[ORM\Index(columns: ['source_table', 'source_column'])]
class JsonPath extends AbstractJsonPath
{
}
