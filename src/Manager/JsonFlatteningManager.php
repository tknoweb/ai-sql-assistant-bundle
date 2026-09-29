<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Tknoweb\AiSqlAssistantBundle\Contract\JsonKeyVocabularyInterface;
use Tknoweb\AiSqlAssistantBundle\Entity\AbstractJsonValue;

/**
 * Rebuilds the table of the flattened JSON values, one row per value of the JSON columns of SchemaManager::getJsonColumns(), and its catalog, so that the assistant queries them in plain SQL.
 * Meant to run every night rather than on each save, the volume being high.
 * Both tables are filled as copies ("<table>_new"), then swapped with the live ones in a single RENAME, so that a query never sees them half built: the schema filter of the connection must
 * hide those copies from the migrations. The memory stays bounded whatever the volume: the source rows are read a few at a time, the values are inserted by batches, and only a hash of each
 * path already met is kept.
 */
class JsonFlatteningManager
{
    private const VALUE_TABLE = 'value';
    private const PATH_TABLE = 'path';
    private const NEW_TABLE_SUFFIX = '_new';
    private const OLD_TABLE_SUFFIX = '_old';

    // Source rows read at once: a JSON content can weigh a few megabytes once decoded, so this is what bounds the memory used
    private const SOURCE_BATCH_SIZE = 5;
    private const INSERT_BATCH_SIZE = 500;
    private const INSERT_BATCH_MAX_BYTES = 4000000;
    private const PATH_ID_COUNT = 3;
    private const NUMBER_VALUE_PATTERN = '/^-?\d{1,18}([.,]\d{1,6})?$/';

    private const LOCK_NAME = 'tknoweb_ai_sql_assistant_json_flattening';

    private const VALUE_COLUMNS = ['source_table', 'source_column', 'source_id', 'path', 'generic_path', 'path_id1', 'path_id2', 'path_id3', 'value', 'number_value'];
    private const PATH_COLUMNS = ['source_table', 'source_column', 'document_type', 'template', 'generic_path', 'labels'];

    private array $insertBuffers = [];
    private array $insertBufferBytes = [];
    private readonly Connection $connection;
    // Table names, by VALUE_TABLE and PATH_TABLE
    private array $tables = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchemaManager $schemaManager,
        private readonly JsonCatalogManager $catalogManager,
        private readonly LockFactory $lockFactory,
        #[Autowire('%tknoweb_ai_sql_assistant.entities%')]
        private readonly array $entities,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    /**
     * Rebuild both tables and return the number of values and catalog paths written, and of values and rows left out (a path too long, a content that is not a JSON object). Null when
     * another rebuild is already running.
     */
    public function rebuild(): ?array
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            $this->tables = [
                self::VALUE_TABLE => $this->entityManager->getClassMetadata($this->entities['json_value'])->getTableName(),
                self::PATH_TABLE => $this->entityManager->getClassMetadata($this->entities['json_path'])->getTableName(),
            ];
            $this->createNewTables();

            $counts = ['values' => 0, 'paths' => 0, 'skippedValues' => 0, 'skippedRows' => 0];
            // Raw md5 of every path already met, catalogable or not, so that each one is only checked and written once
            $metPaths = [];
            foreach ($this->schemaManager->getJsonColumns() as $jsonColumn) {
                $this->flattenColumn($jsonColumn, $metPaths, $counts);
            }

            $this->flushInsertBuffer(self::VALUE_TABLE);
            $this->flushInsertBuffer(self::PATH_TABLE);
            $this->swapTables();

            return $counts;
        } finally {
            $lock->release();
        }
    }

    private function createNewTables(): void
    {
        foreach ($this->tables as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table.self::NEW_TABLE_SUFFIX));
            $this->connection->executeStatement(sprintf('CREATE TABLE %s LIKE %s', $table.self::NEW_TABLE_SUFFIX, $table));
        }
    }

    private function swapTables(): void
    {
        $renames = [];
        foreach ($this->tables as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table.self::OLD_TABLE_SUFFIX));
            $renames[] = sprintf('%1$s TO %1$s%2$s, %1$s%3$s TO %1$s', $table, self::OLD_TABLE_SUFFIX, self::NEW_TABLE_SUFFIX);
        }

        $this->connection->executeStatement('RENAME TABLE '.implode(', ', $renames));

        foreach ($this->tables as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE %s', $table.self::OLD_TABLE_SUFFIX));
        }
    }

    /**
     * Flatten one JSON column, reading its rows by increasing id, SOURCE_BATCH_SIZE at a time.
     */
    private function flattenColumn(array $jsonColumn, array &$metPaths, array &$counts): void
    {
        $sql = sprintf(
            'SELECT id, %1$s AS content%2$s FROM %3$s WHERE id > ? AND %1$s IS NOT NULL ORDER BY id LIMIT %4$d',
            $this->connection->quoteIdentifier($jsonColumn['column']),
            null !== $jsonColumn['discriminatorColumn'] ? ', '.$this->connection->quoteIdentifier($jsonColumn['discriminatorColumn']).' AS document_type' : '',
            $this->connection->quoteIdentifier($jsonColumn['table']),
            self::SOURCE_BATCH_SIZE
        );

        $vocabulary = $this->catalogManager->getVocabulary($jsonColumn['entityClass'], $jsonColumn['column']);
        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative($sql, [$lastId]);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $content = json_decode((string) $row['content'], true);
                if (!is_array($content)) {
                    ++$counts['skippedRows'];

                    continue;
                }

                $documentType = $row['document_type'] ?? null;
                $template = $vocabulary?->getTemplate($content);

                foreach ($this->getLeaves($content) as [$segments, $value]) {
                    $this->addValue($jsonColumn, $vocabulary, $lastId, $documentType, $template, $segments, $value, $metPaths, $counts);
                }

                unset($content);
            }

            $rowCount = count($rows);
            unset($rows);
        } while (self::SOURCE_BATCH_SIZE === $rowCount);
    }

    /**
     * Leaves of a decoded JSON content, as [segments of their path, value], empty values left out since they hold nothing to count.
     */
    private function getLeaves(array $content, array $segments = []): \Generator
    {
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                yield from $this->getLeaves($value, [...$segments, (string) $key]);
            } elseif (null !== $value && '' !== $value) {
                yield [[...$segments, (string) $key], $value];
            }
        }
    }

    private function addValue(
        array $jsonColumn,
        ?JsonKeyVocabularyInterface $vocabulary,
        int $sourceId,
        ?string $documentType,
        ?string $template,
        array $segments,
        mixed $value,
        array &$metPaths,
        array &$counts,
    ): void {
        $words = $vocabulary?->getWords($documentType);
        ['segments' => $genericSegments, 'ids' => $pathIds] = $this->catalogManager->getGenericSegments($segments, $words);

        $path = $this->getPath($segments);
        $genericPath = $this->getPath($genericSegments);
        if (mb_strlen($path) > AbstractJsonValue::PATH_MAX_LENGTH) {
            ++$counts['skippedValues'];

            return;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $this->addInsertRow(self::VALUE_TABLE, [
            $jsonColumn['table'],
            $jsonColumn['column'],
            $sourceId,
            $path,
            $genericPath,
            ...array_pad(array_slice($pathIds, 0, self::PATH_ID_COUNT), self::PATH_ID_COUNT, null),
            (string) $value,
            $this->getNumberValue($value),
        ]);
        ++$counts['values'];

        $pathHash = md5(implode("\0", [$jsonColumn['table'], $jsonColumn['column'], $documentType, $template, $genericPath]), true);
        if (isset($metPaths[$pathHash])) {
            return;
        }

        $metPaths[$pathHash] = true;
        if ($this->catalogManager->isCatalogable($genericSegments, $words)) {
            $this->addInsertRow(self::PATH_TABLE, [
                $jsonColumn['table'],
                $jsonColumn['column'],
                $documentType,
                $template,
                $genericPath,
                $this->getLabelsJson($vocabulary?->getLabels($genericSegments, $documentType, $template)),
            ]);
            ++$counts['paths'];
        }
    }

    private function getLabelsJson(?array $labels): ?string
    {
        return null !== $labels ? json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
    }

    /**
     * Path in the notation of the keys of an HTML form: the root key, then each sub key in brackets.
     */
    private function getPath(array $segments): string
    {
        $path = (string) array_shift($segments);
        foreach ($segments as $segment) {
            $path .= '['.$segment.']';
        }

        return $path;
    }

    /**
     * The value as a DECIMAL, when it is a number or a string holding one, spaces between the digits and a decimal comma included.
     */
    private function getNumberValue(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) && abs($value) < 1e18 ? number_format($value, 6, '.', '') : null;
        }

        $number = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $value));

        return preg_match(self::NUMBER_VALUE_PATTERN, $number) ? str_replace(',', '.', $number) : null;
    }

    private function addInsertRow(string $table, array $row): void
    {
        $this->insertBuffers[$table][] = $row;
        $this->insertBufferBytes[$table] = ($this->insertBufferBytes[$table] ?? 0) + array_sum(array_map(fn (mixed $value) => strlen((string) $value), $row));

        if (count($this->insertBuffers[$table]) >= self::INSERT_BATCH_SIZE || $this->insertBufferBytes[$table] >= self::INSERT_BATCH_MAX_BYTES) {
            $this->flushInsertBuffer($table);
        }
    }

    private function flushInsertBuffer(string $table): void
    {
        $rows = $this->insertBuffers[$table] ?? [];
        $this->insertBuffers[$table] = [];
        $this->insertBufferBytes[$table] = 0;
        if ([] === $rows) {
            return;
        }

        $columns = self::VALUE_TABLE === $table ? self::VALUE_COLUMNS : self::PATH_COLUMNS;
        $rowPlaceholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';

        $this->connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES %s', $this->tables[$table].self::NEW_TABLE_SUFFIX, implode(', ', $columns), implode(', ', array_fill(0, count($rows), $rowPlaceholders))),
            array_merge(...$rows)
        );
    }
}
