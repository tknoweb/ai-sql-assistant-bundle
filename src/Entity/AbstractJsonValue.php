<?php

namespace Tknoweb\AiSqlAssistantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One value of a JSON column of the database, flattened so that the assistant can query it in plain SQL. The table is rebuilt from scratch by JsonFlatteningManager, through DBAL: the
 * application maps it only so that its migrations know it, extending this class with its table name and the indexes below, which a mapped superclass cannot declare:
 * #[ORM\Index(columns: ['json_path_id', 'source_id'])] and #[ORM\Index(columns: ['source_id'])].
 * A value only holds the id of its path, a row of the AbstractJsonPath table, so that the long path texts are stored once rather than on every value. The assistant never reads this table: it
 * queries a view joining each value to its path, under the columns of VIEW_COLUMNS, since the ids of the paths change from one rebuild to the next.
 * The path ids give the numbers a generic path replaced with a star, in their order (the id of a row, a position in a list...).
 * The column names are explicit, whatever the naming strategy of the application, since JsonFlatteningManager writes them in plain SQL.
 */
#[ORM\MappedSuperclass]
abstract class AbstractJsonValue
{
    public const PATH_MAX_LENGTH = 512;

    // Columns of the view the assistant reads the values through, by the table they come from: the value itself or its path
    public const VIEW_COLUMNS = [
        'id' => 'value',
        'source_table' => 'path',
        'source_column' => 'path',
        'source_id' => 'value',
        'generic_path' => 'path',
        'path_id1' => 'value',
        'path_id2' => 'value',
        'path_id3' => 'value',
        'value' => 'value',
        'number_value' => 'value',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    protected ?string $id = null;

    // Id of the AbstractJsonPath row of the path, which also gives the table and the JSON column the value comes from
    #[ORM\Column(name: 'json_path_id')]
    protected int $jsonPathId;

    // Id of the row the value comes from
    #[ORM\Column(name: 'source_id')]
    protected int $sourceId;

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

    public function getJsonPathId(): int
    {
        return $this->jsonPathId;
    }

    public function getSourceId(): int
    {
        return $this->sourceId;
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
