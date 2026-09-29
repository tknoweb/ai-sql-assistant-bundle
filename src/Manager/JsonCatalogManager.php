<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Tknoweb\AiSqlAssistantBundle\Contract\JsonKeyVocabularyInterface;

/**
 * Catalog of the paths of the flattened JSON values, which the assistant searches to write its queries: the keys of a JSON content cannot be listed from the code, so the catalog is made of
 * the paths actually found in the contents, without any value.
 * A user can post any key to a column whose keys come from a form, so such a path only makes the catalog when each word of its keys is known to the JsonKeyVocabularyInterface supporting
 * that column, which also gives the labels of its keys. The keys of the other JSON columns are written by code, and only need to look like field names.
 */
class JsonCatalogManager
{
    public const WILDCARD = '*';

    // A number of more digits would not fit in a BIGINT path id, and is kept as it is
    private const ID_MAX_DIGITS = 18;
    private const FIELD_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/';

    private const MAX_RESULTS = 25;
    // Paths kept from a single kind of document and template version, so that the others still show
    private const MAX_RESULTS_PER_GROUP = 8;
    private const MAX_SEARCHED_WORDS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireIterator('tknoweb_ai_sql_assistant.json_key_vocabulary')]
        private readonly iterable $vocabularies,
        #[Autowire('%tknoweb_ai_sql_assistant.entities%')]
        private readonly array $entities,
    ) {
    }

    /**
     * Paths of the catalog matching the most words of $text, at most MAX_RESULTS of them, grouped by kind of document and template version, along with the labels of their keys and whether
     * more paths matched. A word is looked for in the paths and in their labels, its plural mark left out, and the labels are given once for all the paths to keep the result short.
     */
    public function search(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', mb_strtolower(trim($text))) as $word) {
            // A key is usually singular ("delivered-order"), when the request is often plural
            $word = mb_strlen($word) > 4 ? preg_replace('/[sx]$/u', '', $word) : $word;
            if (mb_strlen($word) >= 2 && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        $words = array_slice($words, 0, self::MAX_SEARCHED_WORDS);
        if ([] === $words) {
            throw new \InvalidArgumentException('The searched text must hold at least one word of two characters.');
        }

        // One more path than returned is fetched, only to know whether the results were cut
        $paths = $this->findMatchingPaths($words, self::MAX_RESULTS + 1, self::MAX_RESULTS_PER_GROUP);

        $groups = [];
        $labels = [];
        foreach (array_slice($paths, 0, self::MAX_RESULTS) as $path) {
            $groupKey = implode('|', [$path['source_table'], $path['source_column'], $path['document_type'], $path['template']]);
            $groups[$groupKey] ??= array_filter([
                'source' => $path['source_table'].'.'.$path['source_column'],
                'document_type' => $path['document_type'],
                'template' => $path['template'],
            ], fn (?string $value) => null !== $value) + ['generic_paths' => [], 'truncated' => false];

            // The path beyond the limit of its group only tells that the group was cut
            if ($path['group_rank'] > self::MAX_RESULTS_PER_GROUP) {
                $groups[$groupKey]['truncated'] = true;

                continue;
            }

            $groups[$groupKey]['generic_paths'][] = $path['generic_path'];

            $labels += null !== $path['labels'] ? json_decode($path['labels'], true, flags: JSON_THROW_ON_ERROR) : [];
        }

        return ['results' => array_values($groups), 'labels' => $labels, 'truncated' => count($paths) > self::MAX_RESULTS];
    }

    /**
     * Vocabulary of the keys of a JSON column, when they come from a form, null when code writes them.
     */
    public function getVocabulary(string $entityClass, string $column): ?JsonKeyVocabularyInterface
    {
        foreach ($this->vocabularies as $vocabulary) {
            if ($vocabulary->supports($entityClass, $column)) {
                return $vocabulary;
            }
        }

        return null;
    }

    /**
     * Generic form of the segments of a path (the root key, then each sub key), each number standing for an id or a position replaced with the wildcard, and those numbers in their order.
     * A segment made of digits only is always one. The key of a form is often a list of words joined by dashes, ending with the id of a row (e.g. "shipping-address-city-123", or even
     * "2-product-option123"): in such a key, $words being the vocabulary of the column, so is a word of digits or ending with digits, unless the vocabulary holds that word as it is (e.g.
     * "iso9001"), and apart from the first word of the root key, which is never an id (it is often the number of a part of the form, e.g. "3-part-...").
     */
    public function getGenericSegments(array $segments, ?array $words): array
    {
        $genericSegments = [];
        $ids = [];
        foreach (array_values($segments) as $segmentIndex => $segment) {
            $segment = (string) $segment;
            if (ctype_digit($segment) && strlen($segment) <= self::ID_MAX_DIGITS) {
                $genericSegments[] = self::WILDCARD;
                $ids[] = $segment;

                continue;
            }

            if (null === $words) {
                $genericSegments[] = $segment;

                continue;
            }

            $segmentWords = explode('-', $segment);
            foreach ($segmentWords as $wordIndex => $word) {
                if ((0 === $segmentIndex && 0 === $wordIndex) || isset($words[$word])) {
                    continue;
                }

                if (preg_match('/^(\D*)(\d{1,'.self::ID_MAX_DIGITS.'})$/', $word, $matches)) {
                    $segmentWords[$wordIndex] = $matches[1].self::WILDCARD;
                    $ids[] = $matches[2];
                }
            }

            $genericSegments[] = implode('-', $segmentWords);
        }

        return ['segments' => $genericSegments, 'ids' => $ids];
    }

    /**
     * Whether a generic path may make the catalog, from its generic segments and the vocabulary of its column, null for a column whose keys code writes.
     */
    public function isCatalogable(array $genericSegments, ?array $words): bool
    {
        foreach ($genericSegments as $segment) {
            $segment = (string) $segment;
            if (self::WILDCARD === $segment) {
                continue;
            }

            if (null === $words) {
                if (!preg_match(self::FIELD_NAME_PATTERN, $segment)) {
                    return false;
                }

                continue;
            }

            foreach (explode('-', $segment) as $word) {
                // A wildcard replaces the digits ending a word
                $word = rtrim($word, self::WILDCARD);
                if ('' !== $word && !isset($words[$word])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Paths whose generic path or labels contain at least one word of $words, those matching the most words first, then the latest templates. At most $groupLimit paths come from the same kind
     * of document and template version, so that a document whose tables multiply the paths (per gender, per year...) does not hide the other documents: one more path of a group is returned,
     * with its "group_rank", only to tell that the group was cut.
     * Written in SQL since the score, the number of words matched, is a sum of conditions DQL cannot order by.
     */
    private function findMatchingPaths(array $words, int $limit, int $groupLimit): array
    {
        // Each condition is written twice, for the score and for the filter, with its own placeholders since a named placeholder can only be used once
        $conditions = ['score' => [], 'filter' => []];
        $parameters = [];
        foreach (array_values($words) as $index => $word) {
            $pattern = '%'.addcslashes($word, '%_\\').'%';
            foreach (array_keys($conditions) as $use) {
                $conditions[$use][] = sprintf("(generic_path LIKE :%1\$s_%2\$d OR COALESCE(labels, '') LIKE :%1\$s_label_%2\$d)", $use, $index);
                $parameters[$use.'_'.$index] = $pattern;
                $parameters[$use.'_label_'.$index] = $pattern;
            }
        }

        $connection = $this->entityManager->getConnection();

        return $connection->fetchAllAssociative(
            sprintf(
                'SELECT source_table, source_column, document_type, template, generic_path, labels, score, group_rank FROM ('
                .'SELECT scored_path.*, ROW_NUMBER() OVER (PARTITION BY source_table, source_column, document_type, template ORDER BY score DESC, generic_path) AS group_rank FROM ('
                .'SELECT source_table, source_column, document_type, template, generic_path, labels, %1$s AS score FROM %2$s WHERE %3$s'
                .') scored_path) ranked_path WHERE group_rank <= %4$d + 1 ORDER BY score DESC, template DESC, source_table, document_type, generic_path LIMIT %5$d',
                implode(' + ', $conditions['score']),
                $connection->quoteIdentifier($this->entityManager->getClassMetadata($this->entities['json_path'])->getTableName()),
                implode(' OR ', $conditions['filter']),
                $groupLimit,
                $limit
            ),
            $parameters
        );
    }
}
