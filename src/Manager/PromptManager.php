<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the system prompt of the assistant: the base instructions of the bundle, naming the SQL dialect of the query connection and the decimals a number keeps, then the documents of the
 * application set in the "prompt" configuration (its own instructions, its business dictionary and the description of its curated views), completed with the codes of the coded columns and
 * their label, generated from the enums so that they never drift from the translations, and with the list of the database tables, generated from the Doctrine mapping (their columns are
 * given on demand by the describe_tables tool).
 */
class PromptManager
{
    private const BASE_INSTRUCTIONS_DOCUMENT = __DIR__.'/../../resources/prompt/instructions.md';
    // Placeholders of the base instructions standing for the SQL dialect of the database and for the decimals a displayed number keeps at most
    private const SQL_DIALECT_PLACEHOLDER = '{sql_dialect}';
    private const MAX_DECIMALS_PLACEHOLDER = '{max_decimals}';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly SchemaManager $schemaManager,
        private readonly QueryManager $queryManager,
        #[Autowire('%tknoweb_ai_sql_assistant.prompt%')]
        private readonly array $documents,
        #[Autowire('%tknoweb_ai_sql_assistant.coded_columns%')]
        private readonly array $codedColumns,
        #[Autowire('%tknoweb_ai_sql_assistant.coded_values_translation_domain%')]
        private readonly string $codedValuesTranslationDomain,
        #[Autowire('%tknoweb_ai_sql_assistant.locale%')]
        private readonly string $locale,
    ) {
    }

    /**
     * Texts of the blocks of the system prompt. Their content is the same on every request, which lets the model API read them from its prompt cache instead of billing them at the full
     * input price: anything varying from one request to another must therefore stay out of them.
     */
    public function getSystemTexts(): array
    {
        $instructions = str_replace(
            [self::SQL_DIALECT_PLACEHOLDER, self::MAX_DECIMALS_PLACEHOLDER],
            [$this->queryManager->getDialect()->getName(), (string) $this->queryManager->getMaxDecimals()],
            $this->getDocument(self::BASE_INSTRUCTIONS_DOCUMENT),
        );
        if (null !== $this->documents['instructions']) {
            $instructions .= "\n\n".$this->getDocument($this->documents['instructions']);
        }

        $blocks = [$instructions];
        if (null !== $this->documents['dictionary']) {
            $blocks[] = "<business_dictionary>\n".$this->getDocument($this->documents['dictionary'])."\n</business_dictionary>";
        }

        $database = array_filter([
            null !== $this->documents['database'] ? $this->getDocument($this->documents['database']) : null,
            [] !== $this->codedColumns ? $this->getCodeLists() : null,
            "## Database tables\n\n".$this->schemaManager->getTableList(),
        ]);
        $blocks[] = "<database>\n".implode("\n\n", $database)."\n</database>";

        return $blocks;
    }

    private function getDocument(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if (false === $content) {
            throw new \RuntimeException(sprintf('The assistant document "%s" cannot be read.', $path));
        }

        return trim($content);
    }

    private function getCodeLists(): string
    {
        $codeLists = [];
        foreach ($this->codedColumns as $column => $enumClass) {
            $codes = array_map(
                fn (\BackedEnum $case) => sprintf('- `%s`: %s', $case->value, $this->translator->trans((string) $case->value, domain: $this->codedValuesTranslationDomain, locale: $this->locale)),
                $enumClass::cases()
            );
            $codeLists[] = sprintf("### %s\n\n%s", $column, implode("\n", $codes));
        }

        return "## Codes of the coded columns\n\n".implode("\n\n", $codeLists);
    }
}
