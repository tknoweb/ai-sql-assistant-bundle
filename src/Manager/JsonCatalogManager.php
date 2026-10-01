<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Tknoweb\AiSqlAssistantBundle\Contract\JsonKeyVocabularyInterface;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlDialect;

/**
 * Catalog of the paths of the flattened JSON values, which the assistant searches to write its queries: the keys of a JSON content cannot be listed from the code, so the catalog is made of
 * the paths actually found in the contents, without any value.
 * A user can post any key to a column whose keys come from a form, so such a path only makes the catalog when each word of its keys is known to the JsonKeyVocabularyInterface supporting
 * that column, which also gives the labels of its keys. The keys of the other JSON columns are written by code, and only need to look like field names. The table of the paths holds the
 * others too, flagged as not catalogable, since every value points to its path: the search leaves them out.
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
    // Shortest beginning shared by the labels of a group that is given apart, in bytes
    private const MIN_LABEL_PREFIX_LENGTH = 20;
    // Escape character of the LIKE patterns, set explicitly since the engines differ in their default one
    private const LIKE_ESCAPE_CHARACTER = '!';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireIterator('tknoweb_ai_sql_assistant.json_key_vocabulary')]
        private readonly iterable $vocabularies,
        #[Autowire('%tknoweb_ai_sql_assistant.entities%')]
        private readonly array $entities,
    ) {
    }

    /**
     * Paths of the catalog matching the most words of $text, at most MAX_RESULTS of them, grouped by kind of document and template version, each group with the labels of its paths, and
     * whether more paths matched. A word is looked for in the paths and in their labels, its plural mark left out. $documentType and $template restrict the search to one kind of document or
     * template version, the latest versions coming first otherwise. The labels stay in their group, a key being able to change its label from one template version to the next, and the
     * beginning they share is given once per group, the labels of a form repeating the titles of its parts and tables.
     */
    public function search(string $text, ?string $documentType = null, ?string $template = null): array
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
        $paths = $this->findMatchingPaths($words, self::MAX_RESULTS + 1, self::MAX_RESULTS_PER_GROUP, $documentType, $template);

        $groups = [];
        foreach (array_slice($paths, 0, self::MAX_RESULTS) as $path) {
            $groupKey = implode('|', [$path['source_table'], $path['source_column'], $path['document_type'], $path['template']]);
            $groups[$groupKey] ??= array_filter([
                'source' => $path['source_table'].'.'.$path['source_column'],
                'document_type' => $path['document_type'],
                'template' => $path['template'],
            ], fn (?string $value) => null !== $value) + ['generic_paths' => [], 'labels' => [], 'truncated' => false];

            // The path beyond the limit of its group only tells that the group was cut
            if ($path['group_rank'] > self::MAX_RESULTS_PER_GROUP) {
                $groups[$groupKey]['truncated'] = true;

                continue;
            }

            $groups[$groupKey]['generic_paths'][] = $path['generic_path'];
            $groups[$groupKey]['labels'] += null !== $path['labels'] ? json_decode($path['labels'], true, flags: JSON_THROW_ON_ERROR) : [];
        }

        return ['results' => array_map(fn (array $group) => $this->getCompactGroup($group), array_values($groups)), 'truncated' => count($paths) > self::MAX_RESULTS];
    }

    /**
     * A group of paths as the model reads it: without labels when none of its keys has one (the keys of a JSON column written by code, for instance), and with the beginning its labels
     * share given once, as "label_prefix", each label keeping the rest.
     */
    private function getCompactGroup(array $group): array
    {
        $labels = array_map('strval', $group['labels']);
        $truncated = $group['truncated'];
        unset($group['labels'], $group['truncated']);

        if ([] !== $labels) {
            $prefix = $this->getSharedLabelPrefix($labels);
            if ('' !== $prefix) {
                $group['label_prefix'] = rtrim($prefix);
                $labels = array_map(fn (string $label) => substr($label, strlen($prefix)), $labels);
            }

            $group['labels'] = $labels;
        }

        $group['truncated'] = $truncated;

        return $group;
    }

    /**
     * Beginning shared by several labels, up to the end of a word so that no word nor multibyte character is cut, empty when too short to be worth giving apart.
     */
    private function getSharedLabelPrefix(array $labels): string
    {
        if (count($labels) < 2) {
            return '';
        }

        $prefix = array_shift($labels);
        foreach ($labels as $label) {
            // The bytes two strings share at their start are the leading zero bytes of their exclusive or
            $prefix = substr($prefix, 0, strspn($prefix ^ $label, "\0"));
        }

        $prefix = substr($prefix, 0, (int) strrpos($prefix, ' ') + 1);

        return strlen($prefix) >= self::MIN_LABEL_PREFIX_LENGTH ? $prefix : '';
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
     * Written in SQL since the score, the number of words matched, is a sum of conditions DQL cannot order by. The words being in lower case, so are the paths and labels they are compared
     * to, whatever the collation of the engine. A kind of document or a template version given restricts the paths to it.
     */
    private function findMatchingPaths(array $words, int $limit, int $groupLimit, ?string $documentType, ?string $template): array
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Each condition is written twice, for the score and for the filter, with its own placeholders since a named placeholder can only be used once
        $conditions = ['score' => [], 'filter' => []];
        $parameters = [];
        foreach (array_values($words) as $index => $word) {
            $pattern = '%'.$platform->escapeStringForLike($word, self::LIKE_ESCAPE_CHARACTER).'%';
            foreach (array_keys($conditions) as $use) {
                $conditions[$use][] = sprintf(
                    "(LOWER(generic_path) LIKE :%1\$s_%2\$d ESCAPE '%3\$s' OR LOWER(COALESCE(labels, '')) LIKE :%1\$s_label_%2\$d ESCAPE '%3\$s')",
                    $use,
                    $index,
                    self::LIKE_ESCAPE_CHARACTER
                );
                $parameters[$use.'_'.$index] = $pattern;
                $parameters[$use.'_label_'.$index] = $pattern;
            }
        }

        // Only the paths whose keys are vetted reach the model
        $filter = 'catalogable = :catalogable AND ('.implode(' OR ', $conditions['filter']).')';
        $parameters['catalogable'] = true;
        foreach (['document_type' => $documentType, 'template' => $template] as $column => $value) {
            if (null !== $value) {
                $filter .= sprintf(' AND %1$s = :restricted_%1$s', $column);
                $parameters['restricted_'.$column] = $value;
            }
        }

        return $connection->fetchAllAssociative(
            $platform->modifyLimitQuery(sprintf(
                'SELECT source_table, source_column, document_type, template, generic_path, labels, score, group_rank FROM ('
                .'SELECT scored_path.*, ROW_NUMBER() OVER (PARTITION BY source_table, source_column, document_type, template ORDER BY score DESC, generic_path) AS group_rank FROM ('
                .'SELECT source_table, source_column, document_type, template, generic_path, labels, %1$s AS score FROM %2$s WHERE %3$s'
                .') scored_path) ranked_path WHERE group_rank <= %4$d + 1 ORDER BY score DESC, template DESC, source_table, document_type, generic_path',
                implode(' + ', array_map(fn (string $condition) => sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $condition), $conditions['score'])),
                SqlDialect::quoteName($platform, $this->entityManager->getClassMetadata($this->entities['json_path'])->getTableName()),
                $filter,
                $groupLimit
            ), $limit),
            $parameters,
            ['catalogable' => Types::BOOLEAN]
        );
    }
}
