<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Describes the database tables the assistant may query, from the Doctrine mapping, so that a new table or column becomes available without any change here: every mapped table but the
 * forbidden ones, and every column but the forbidden ones, as set in the "forbidden" configuration of the bundle. The read-only database user of the assistant can read the whole database, so
 * this list is what keeps them out: QueryManager refuses any query naming them. The conversations and their log are always forbidden, since they hold the requests of the other users.
 * The descriptions only hold names, types and codes, never a stored value, so they can be sent to the model.
 */
class SchemaManager
{
    private ?array $tables = null;
    private ?array $forbiddenNames = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%tknoweb_ai_sql_assistant.forbidden.entities%')]
        private readonly array $forbiddenEntities,
        #[Autowire('%tknoweb_ai_sql_assistant.forbidden.tables%')]
        private readonly array $forbiddenTables,
        #[Autowire('%tknoweb_ai_sql_assistant.forbidden.fields%')]
        private readonly array $forbiddenFields,
        #[Autowire('%tknoweb_ai_sql_assistant.forbidden.field_attributes%')]
        private readonly array $forbiddenFieldAttributes,
        #[Autowire('%tknoweb_ai_sql_assistant.unflattened_json_entities%')]
        private readonly array $unflattenedJsonEntities,
        #[Autowire('%tknoweb_ai_sql_assistant.entities%')]
        private readonly array $entities,
    ) {
    }

    /**
     * Names the assistant queries must never contain, forbidden tables and columns alike.
     */
    public function getForbiddenNames(): array
    {
        if (null === $this->forbiddenNames) {
            $forbiddenNames = $this->forbiddenTables;
            foreach ($this->getAllMetadata() as $metadata) {
                if ($this->isForbiddenEntity($metadata)) {
                    $forbiddenNames[] = $metadata->getTableName();
                }

                $forbiddenNames = array_merge($forbiddenNames, $this->getForbiddenColumns($metadata));
            }

            $this->forbiddenNames = array_values(array_unique($forbiddenNames));
        }

        return $this->forbiddenNames;
    }

    /**
     * Readable tables, by name: an optional description taken from the entity docblock, and their columns with their type, the table and column they reference, and their codes when an
     * enum or a discriminator backs them.
     */
    public function getTables(): array
    {
        if (null !== $this->tables) {
            return $this->tables;
        }

        $metadatas = $this->getAllMetadata();
        $forbiddenNames = $this->getForbiddenNames();

        // Codes and descriptions are gathered by table first, the classes of a single table inheritance sharing the table of their root entity
        $descriptions = [];
        $codes = [];
        foreach ($metadatas as $metadata) {
            $tableName = $metadata->getTableName();
            if ($metadata->name === $metadata->rootEntityName) {
                $descriptions[$tableName] = $this->getDescription($metadata);
            }

            foreach ($metadata->fieldMappings as $fieldMapping) {
                if (null !== $fieldMapping->enumType) {
                    $codes[$tableName][$fieldMapping->columnName] = array_map(fn (\BackedEnum $case) => (string) $case->value, $fieldMapping->enumType::cases());
                }
            }

            if (null !== $metadata->discriminatorColumn) {
                $codes[$tableName][$metadata->discriminatorColumn->name] = array_map('strval', array_keys($metadata->discriminatorMap));
            }
        }

        $tables = [];
        foreach ((new SchemaTool($this->entityManager))->getSchemaFromMetadata($metadatas)->getTables() as $table) {
            $tableName = $this->getUnquotedName($table->getName());
            if (in_array($tableName, $forbiddenNames, true)) {
                continue;
            }

            $references = [];
            foreach ($table->getForeignKeys() as $foreignKey) {
                foreach ($foreignKey->getLocalColumns() as $index => $localColumn) {
                    $references[$this->getUnquotedName($localColumn)] = $this->getUnquotedName($foreignKey->getForeignTableName()).'.'.$this->getUnquotedName($foreignKey->getForeignColumns()[$index]);
                }
            }

            $columns = [];
            foreach ($table->getColumns() as $column) {
                $columnName = $this->getUnquotedName($column->getName());
                if (in_array($columnName, $forbiddenNames, true)) {
                    continue;
                }

                $columns[$columnName] = array_filter([
                    'type' => Type::getTypeRegistry()->lookupName($column->getType()),
                    'nullable' => !$column->getNotnull(),
                    'references' => $references[$columnName] ?? null,
                    'codes' => $codes[$tableName][$columnName] ?? null,
                ]);
            }

            // A table without any entity of its own is the join table of a many-to-many association
            $tables[$tableName] = [
                'description' => array_key_exists($tableName, $descriptions) ? $descriptions[$tableName] : 'Link table of a many-to-many association.',
                'columns' => $columns,
            ];
        }

        ksort($tables);

        return $this->tables = $tables;
    }

    public function getJsonValueTableName(): string
    {
        return $this->entityManager->getClassMetadata($this->entities['json_value'])->getTableName();
    }

    /**
     * Readable tables with their description, one line each, for the system prompt.
     */
    public function getTableList(): string
    {
        $lines = [];
        foreach ($this->getTables() as $tableName => $table) {
            $lines[] = null !== $table['description'] ? sprintf('- `%s`: %s', $tableName, $table['description']) : sprintf('- `%s`', $tableName);
        }

        return implode("\n", $lines);
    }

    /**
     * Columns of the given tables, as plain text for the model. An unknown or forbidden table is reported as such, without telling one from the other.
     */
    public function describeTables(array $tableNames): string
    {
        $tables = $this->getTables();
        $descriptions = [];
        foreach (array_unique($tableNames) as $tableName) {
            if (!isset($tables[$tableName])) {
                $descriptions[] = sprintf("## %s\n\nUnknown table: check the list of the database tables.", $tableName);

                continue;
            }

            $lines = [];
            foreach ($tables[$tableName]['columns'] as $columnName => $column) {
                $line = sprintf('- `%s` %s', $columnName, $column['type']);
                if ($column['nullable'] ?? false) {
                    $line .= ', nullable';
                }
                if (isset($column['references'])) {
                    $line .= ', references '.$column['references'];
                }
                if (isset($column['codes'])) {
                    $line .= ', codes: '.implode(', ', $column['codes']);
                }
                if (Types::SIMPLE_ARRAY === $column['type']) {
                    $line .= ' (comma separated values)';
                }

                $lines[] = $line;
            }

            $descriptions[] = sprintf("## %s\n\n%s", $tableName, implode("\n", $lines));
        }

        return implode("\n\n", $descriptions);
    }

    /**
     * JSON columns to flatten, as ["table", "column", "discriminatorColumn", "entityClass"]: the discriminator column of the table, if any, and the entity class, from which JsonCatalogManager
     * finds whether the keys of the column are posted by the users.
     */
    public function getJsonColumns(): array
    {
        $forbiddenNames = $this->getForbiddenNames();
        $jsonColumns = [];
        foreach ($this->getAllMetadata() as $metadata) {
            $tableName = $metadata->getTableName();
            // The flattening reads the rows by increasing id
            if (in_array($tableName, $forbiddenNames, true) || in_array($metadata->name, $this->unflattenedJsonEntities, true) || ['id'] !== $metadata->getIdentifierColumnNames()) {
                continue;
            }

            foreach ($metadata->fieldMappings as $fieldMapping) {
                if (Types::JSON !== $fieldMapping->type || in_array($fieldMapping->columnName, $forbiddenNames, true)) {
                    continue;
                }

                $jsonColumns[$tableName.'.'.$fieldMapping->columnName] = [
                    'table' => $tableName,
                    'column' => $fieldMapping->columnName,
                    'discriminatorColumn' => $metadata->discriminatorColumn?->name,
                    'entityClass' => $metadata->rootEntityName,
                ];
            }
        }

        return array_values($jsonColumns);
    }

    /**
     * Metadata of the entities that have a table of their own or share the one of their root entity, mapped superclasses and embeddables left out.
     */
    private function getAllMetadata(): array
    {
        return array_values(array_filter(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
            fn (ClassMetadata $metadata) => !$metadata->isMappedSuperclass && !$metadata->isEmbeddedClass
        ));
    }

    private function isForbiddenEntity(ClassMetadata $metadata): bool
    {
        foreach ([...$this->forbiddenEntities, $this->entities['conversation'], $this->entities['exchange']] as $forbiddenEntity) {
            if (is_a($metadata->name, $forbiddenEntity, true)) {
                return true;
            }
        }

        return false;
    }

    private function getForbiddenColumns(ClassMetadata $metadata): array
    {
        $forbiddenColumns = [];
        foreach ($metadata->fieldMappings as $fieldName => $fieldMapping) {
            if (in_array($fieldName, $this->forbiddenFields, true)) {
                $forbiddenColumns[] = $fieldMapping->columnName;

                continue;
            }

            // A field of an embeddable is named "<property>.<field>", its attributes being on the embeddable class
            if (str_contains($fieldName, '.')) {
                continue;
            }

            $property = $this->getDeclaredProperty($metadata->getReflectionClass(), $fieldName);
            foreach ($this->forbiddenFieldAttributes as $attributeClass) {
                if (null !== $property && [] !== $property->getAttributes($attributeClass)) {
                    $forbiddenColumns[] = $fieldMapping->columnName;
                }
            }
        }

        return $forbiddenColumns;
    }

    /**
     * Property as the class declaring it sees it, where its attributes are: a private property of a parent class, the root of a single table inheritance for instance, is invisible from its
     * child classes.
     */
    private function getDeclaredProperty(\ReflectionClass $class, string $name): ?\ReflectionProperty
    {
        for (; false !== $class; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name);
            }
        }

        return null;
    }

    /**
     * First paragraph of the entity docblock, unless it only says "<Name> entity", which tells nothing the table name does not.
     */
    private function getDescription(ClassMetadata $metadata): ?string
    {
        $docComment = $metadata->getReflectionClass()->getDocComment();
        if (false === $docComment) {
            return null;
        }

        // Horizontal spaces only, so that the empty line between two paragraphs is kept
        $text = trim(preg_replace('/^[ \t]*(\/\*\*|\*\/|\*)[ \t]?/m', '', $docComment));
        $description = trim(preg_replace('/\s+/', ' ', explode("\n\n", $text)[0]));

        return '' === $description || preg_match('/^\w+ entity\.?$/i', $description) ? null : $description;
    }

    private function getUnquotedName(string $name): string
    {
        return trim($name, '`');
    }
}
