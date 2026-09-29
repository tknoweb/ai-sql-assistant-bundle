<?php

namespace Tknoweb\AiSqlAssistantBundle\Contract;

/**
 * Public referentials the model may search, so that its queries use the exact names of the database (a customer, a city...). They are the only data the model ever reads: an application
 * must only expose there what it accepts to send to the AI provider. The "search_public_referential" tool is only offered when a provider is configured.
 */
interface ReferentialProviderInterface
{
    /**
     * Names of the referentials, offered to the model as the possible values of the tool.
     */
    public function getReferentials(): array;

    /**
     * What the model should know of these referentials, added to the description of the tool, e.g. which rows they hold and how many entries a search returns at most.
     */
    public function getDescription(): string;

    /**
     * Entries of a referential matching $text, as a JSON serializable array. An unknown referential or an empty text is refused with an InvalidArgumentException, whose message goes back to
     * the model.
     */
    public function search(string $referential, string $text): array;
}
