<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures;

use Tknoweb\AiSqlAssistantBundle\Contract\JsonKeyVocabularyInterface;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Document;

/**
 * Vocabulary of the documents of the test application, whose keys are typed in online forms: the words of each document type, and a label for a few generic keys of the "v2" template.
 */
class FormKeyVocabulary implements JsonKeyVocabularyInterface
{
    public const WORDS = [
        'form' => ['3', 'part', 'price', 'median', 'product', 'option', 'template', 'iso9001'],
        'report' => ['summary', 'rate', 'template'],
    ];

    public const LABELS = [
        '3-part-price-median-*' => 'Median price of the products',
        'product-option*' => 'Option of the product',
    ];

    public function supports(string $entityClass, string $column): bool
    {
        return Document::class === $entityClass && 'content' === $column;
    }

    public function getWords(?string $documentType): array
    {
        return array_fill_keys(self::WORDS[$documentType] ?? [], true);
    }

    public function getLabels(array $genericSegments, ?string $documentType, ?string $template): ?array
    {
        if ('v2' !== $template) {
            return null;
        }

        $labels = array_intersect_key(self::LABELS, array_flip($genericSegments));

        return [] !== $labels ? $labels : null;
    }

    public function getTemplate(array $content): ?string
    {
        $template = $content['template'] ?? null;

        return is_string($template) && preg_match('/^v\d+$/', $template) ? $template : null;
    }
}
