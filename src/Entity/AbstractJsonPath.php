<?php

namespace Tknoweb\AiSqlAssistantBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One generic path found in the flattened JSON values, for one kind of document: the catalog the assistant searches to write its queries on those values, since the keys of a JSON content
 * cannot be read from the code. Rebuilt along with the values, it holds no stored value, and a path whose keys the users post only makes it once each word of its keys is known to a
 * JsonKeyVocabularyInterface. The application extends it with its table name and the index #[ORM\Index(columns: ['source_table', 'source_column'])].
 * The column names are explicit, whatever the naming strategy of the application, since JsonFlatteningManager and JsonCatalogManager write them in plain SQL.
 */
#[ORM\MappedSuperclass]
abstract class AbstractJsonPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(name: 'source_table', length: 64)]
    protected string $sourceTable;

    #[ORM\Column(name: 'source_column', length: 64)]
    protected string $sourceColumn;

    // Discriminator value of the source row, when its table holds several kinds of entities
    #[ORM\Column(name: 'document_type', length: 64, nullable: true)]
    protected ?string $documentType = null;

    // Template version of a form typed online, e.g. "v2"
    #[ORM\Column(name: 'template', length: 64, nullable: true)]
    protected ?string $template = null;

    #[ORM\Column(name: 'generic_path', length: AbstractJsonValue::PATH_MAX_LENGTH)]
    protected string $genericPath;

    // Labels of the keys of the path found in the templates, as a JSON object by key
    #[ORM\Column(name: 'labels', type: 'text', nullable: true)]
    protected ?string $labels = null;

    public function getId(): ?int
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

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function getGenericPath(): string
    {
        return $this->genericPath;
    }

    public function getLabels(): ?string
    {
        return $this->labels;
    }
}
