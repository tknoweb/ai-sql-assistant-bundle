<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Manager\PromptManager;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;

/**
 * The system prompt of the test application: the base instructions of the bundle, then the documents of the application, the codes of its coded columns and its tables.
 */
class PromptManagerTest extends FunctionalTestCase
{
    public function testBuildsTheSameSystemPromptOnEveryRequest(): void
    {
        self::bootKernel();
        $promptManager = static::getContainer()->get(PromptManager::class);

        $systemTexts = $promptManager->getSystemTexts();

        $this->assertCount(3, $systemTexts);
        $this->assertStringStartsWith('# SQL assistant', $systemTexts[0]);
        // The dialect of the query connection, the SQLite file of the tests unless another database is set
        $this->assertStringContainsString(sprintf('in %s syntax', static::getContainer()->get(QueryManager::class)->getDialect()->getName()), $systemTexts[0]);
        $this->assertStringEndsWith("# Test application\n\nThe users of the test application are the staff of a retail chain. Write in English.", $systemTexts[0]);
        $this->assertSame("<business_dictionary>\n# Dictionary\n\n- **Store**: an open store by default, a closed one only when the user asks for it.\n</business_dictionary>", $systemTexts[1]);

        $database = $systemTexts[2];
        $this->assertStringStartsWith("<database>\n# Curated views\n\nThe test application has no curated view: query the tables.\n\n## Codes of the coded columns\n\n", $database);
        // The codes are labelled in the locale of the prompt, not in the one of the request
        $this->assertStringContainsString("### store_status\n\n- `open`: Ouvert\n- `closed`: Fermé", $database);
        $this->assertStringContainsString("## Database tables\n\n- `audit_log`\n- `document`: Document of a store", $database);
        $this->assertStringEndsWith("\n</database>", $database);

        // Nothing varies from one request to the next, for the prompt cache
        $this->assertSame($systemTexts, $promptManager->getSystemTexts());
    }
}
