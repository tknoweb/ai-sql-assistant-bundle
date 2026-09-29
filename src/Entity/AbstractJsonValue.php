<?php

namespace Tknoweb\AiSqlAssistantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One value of a JSON column of the database, flattened so that the assistant can query it in plain SQL. The table is rebuilt from scratch by JsonFlatteningManager, through DBAL: the
 * application maps it only so that its migrations know it, extending this class with its table name and the indexes below, which a mapped superclass cannot declare:
 * #[ORM\Index(columns: ['source_table', 'source_column', 'generic_path'])] and #[ORM\Index(columns: ['source_table', 'source_id'])].
 * The path follows the keys of the content, e.g. "order[items][123][unit-price]": the root key, then each sub key in brackets. The generic path replaces each id or position of the path
 * with a star, and the path ids give those numbers in their order (the id of a row, a position in a list...).
 * The column names are explicit, whatever the naming strategy of the application, since JsonFlatteningManager writes them in plain SQL.
 */
#[ORM\MappedSuperclass]
abstract class AbstractJsonValue
{
    public const PATH_MAX_LENGTH = 512;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    protected ?string $id = null;

    // Table and JSON column the value comes from, and id of its row
    #[ORM\Column(name: 'source_table', length: 64)]
    protected string $sourceTable;

    #[ORM\Column(name: 'source_column', length: 64)]
    protected string $sourceColumn;

    #[ORM\Column(name: 'source_id')]
    protected int $sourceId;

    #[ORM\Column(name: 'path', length: self::PATH_MAX_LENGTH)]
    protected string $path;

    #[ORM\Column(name: 'generic_path', length: self::PATH_MAX_LENGTH)]
    protected string $genericPath;

    #[ORM\Column(name: 'path_id1', type: 'bigint', nullable: true)]
    protected ?string $pathId1 = null;

    #[ORM\Column(name: 'path_id2', type: 'bigint', nullable: true)]
    protected ?string $pathId2 = null;

    #[ORM\Column(name: 'path_id3', type: 'bigint', nullable: true)]
    protected ?string $pathId3 = null;

    #[ORM\Column(name: 'value', type: 'text')]
    protected string $value;

    // The value as a number, when it is one
    #[ORM\Column(name: 'number_value', type: 'decimal', precision: 24, scale: 6, nullable: true)]
    protected ?string $numberValue = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSourceTable(): string
    {
        return $this->sourceTable;
    }

    public function getSourceColumn(): string
    {
        return $this->sourceColumn;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getGenericPath(): string
    {
        return $this->genericPath;
    }

    public function getPathId1(): ?string
    {
        return $this->pathId1;
    }

    public function getPathId2(): ?string
    {
        return $this->pathId2;
    }

    public function getPathId3(): ?string
    {
        return $this->pathId3;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getNumberValue(): ?string
    {
        return $this->numberValue;
    }
}
