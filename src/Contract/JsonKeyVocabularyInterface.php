<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

/**
 * Keys a JSON column can hold, for the columns whose keys are posted by the users (the content of a form typed online, for instance). The catalog of the flattened paths is sent to the model,
 * so such a path only makes it when every word of its keys is known here: a user could otherwise post a key written to manipulate the model. The columns no vocabulary supports only need keys
 * that look like field names. Services implementing this interface are registered automatically.
 */
interface JsonKeyVocabularyInterface
{
    public function supports(string $entityClass, string $column): bool;

    /**
     * Words the keys of a document of this type can be made of, as array keys. A key is split into words on its dashes.
     */
    public function getWords(?string $documentType): array;

    /**
     * Labels of the segments of a generic path, by segment, as the forms of that document type and version show them, null when none has any.
     */
    public function getLabels(array $genericSegments, ?string $documentType, ?string $template): ?array;

    /**
     * Version of the form a decoded content was typed in, when the content records it, the keys depending on it.
     */
    public function getTemplate(array $content): ?string;
}
