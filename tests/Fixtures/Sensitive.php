<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures;

/**
 * Marks an entity property the queries of the model must never read, set in the "forbidden.field_attributes" configuration of the test application.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Sensitive
{
}
