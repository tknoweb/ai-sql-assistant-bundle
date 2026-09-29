<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures;

use Tknoweb\AiSqlAssistantBundle\Contract\ReferentialProviderInterface;

/**
 * Public referential of the test application: a fixed list of cities.
 */
class PublicReferentialProvider implements ReferentialProviderInterface
{
    public const CITIES = ['Lyon', 'Lille', 'Paris'];

    public function getReferentials(): array
    {
        return ['city'];
    }

    public function getDescription(): string
    {
        return 'The city referential lists the cities of the stores.';
    }

    public function search(string $referential, string $text): array
    {
        if ('city' !== $referential || '' === trim($text)) {
            throw new \InvalidArgumentException('Unknown referential or empty text.');
        }

        return array_values(array_filter(self::CITIES, fn (string $city) => str_contains(mb_strtolower($city), mb_strtolower(trim($text)))));
    }
}
