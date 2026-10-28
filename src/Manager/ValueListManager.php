<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists the distinct values of the columns and of the JSON fields the application declares listable (the "listable_values" configuration of the bundle), so that the model filters a query on
 * a code or a name with its exact spelling rather than guess it and get an empty result. Like the public referentials, these values reach the model: the application alone decides which ones,
 * none by default. A listable column must be a readable text column of a table, and a listable field a generic path of the flattened JSON values. The values come without any count, at most
 * MAX_VALUES of them, each one cut to MAX_VALUE_LENGTH characters.
 */
class ValueListManager
{
    public const MAX_VALUES = 100;
    public const MAX_VALUE_LENGTH = 100;

    // Doctrine types of the columns holding text, the only ones whose values can be listed: the codes of an enum are already given by the table descriptions
    private const TEXT_TYPES = [Types::STRING, Types::ASCII_STRING, Types::TEXT];
    // Columns of the view of the flattened JSON values (AbstractJsonValue::VIEW_COLUMNS) a field is listed through
    private const JSON_VALUE_COLUMN = 'value';
    private const JSON_PATH_COLUMN = 'generic_path';

    private bool $validated = false;

    public function __construct(
        private readonly QueryManager $queryManager,
        private readonly SchemaManager $schemaManager,
        #[Autowire('%tknoweb_ai_sql_assistant.listable_values.columns%')]
        private readonly array $columns = [],
        #[Autowire('%tknoweb_ai_sql_assistant.listable_values.json_paths%')]
        private readonly array $jsonPaths = [],
    ) {
    }

    /**
     * Columns, as "table.column", then generic paths of the JSON fields whose values the model may list, in the order of the configuration.
     */
    public function getSources(): array
    {
        $this->validate();

        return array_values(array_unique([...$this->columns, ...$this->jsonPaths]));
    }

    /**
     * Distinct values of a listable column or field, sorted, the ones containing $text when it is given (the case ignored), and whether more values exist beyond the ones returned.
     */
    public function listValues(string $source, ?string $text): array
    {
        $this->validate();

        // One more value than returned is fetched, only to know whether the list was cut
        if (in_array($source, $this->columns, true)) {
            [$table, $column] = explode('.', $source, 2);
            $values = $this->queryManager->fetchDistinctValues($table, $column, [], $text, self::MAX_VALUES + 1);
        } elseif (in_array($source, $this->jsonPaths, true)) {
            $values = $this->queryManager->fetchDistinctValues($this->schemaManager->getJsonValueViewName(), self::JSON_VALUE_COLUMN, [self::JSON_PATH_COLUMN => $source], $text, self::MAX_VALUES + 1);
        } else {
            throw new \InvalidArgumentException(sprintf('"%s" is not a listable column or field: give one of the sources of the tool.', $source));
        }

        return [
            'values' => array_map(
                fn (string $value) => mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…' : $value,
                array_slice($values, 0, self::MAX_VALUES)
            ),
            'moreValues' => count($values) > self::MAX_VALUES,
        ];
    }

    /**
     * A listable column must be a readable text column of a table, the forbidden ones being unknown to SchemaManager: a mistake of the configuration stops the assistant rather than open
     * another column. The view of the flattened JSON values is no listable column, since it would give the values of every field: a field is listed by its path.
     */
    private function validate(): void
    {
        if ($this->validated) {
            return;
        }

        $tables = $this->schemaManager->getTables();
        foreach ($this->columns as $source) {
            [$table, $column] = array_pad(explode('.', $source, 2), 2, '');
            $type = $tables[$table]['columns'][$column]['type'] ?? null;
            if (null === $type || $table === $this->schemaManager->getJsonValueViewName()) {
                throw new \LogicException(sprintf('The listable column "%s" of the assistant is not a readable column of a table: check its "table.column" name and the "forbidden" configuration, and list a JSON field by its path in "json_paths".', $source));
            }

            if (!in_array($type, self::TEXT_TYPES, true)) {
                throw new \LogicException(sprintf('The listable column "%s" of the assistant is of type "%s": only the values of a text column can be listed.', $source, $type));
            }
        }

        $this->validated = true;
    }
}
