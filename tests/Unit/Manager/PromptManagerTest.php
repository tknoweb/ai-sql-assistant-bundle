<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Tknoweb\AiSqlAssistantBundle\Dialect\SqlServerDialect;
use Tknoweb\AiSqlAssistantBundle\Manager\PromptManager;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;

/**
 * The system prompt without the optional documents of the application. With all of them, it is checked in the test application (Functional\PromptManagerTest).
 */
class PromptManagerTest extends TestCase
{
    public function testBuildsThePromptFromTheBaseInstructionsAndTheTableList(): void
    {
        $schemaManager = $this->createStub(SchemaManager::class);
        $schemaManager->method('getTableList')->willReturn('- `store`');

        $systemTexts = $this->createPromptManager($schemaManager, null)->getSystemTexts();

        $this->assertCount(2, $systemTexts);
        $instructions = trim(file_get_contents(dirname(__DIR__, 3).'/resources/prompt/instructions.md'));
        $this->assertStringContainsString('{sql_dialect}', $instructions);
        $this->assertStringContainsString('{max_decimals}', $instructions);
        $this->assertSame(str_replace(['{sql_dialect}', '{max_decimals}'], ['SQL Server (Transact-SQL)', '3'], $instructions), $systemTexts[0]);
        $this->assertStringContainsString('in SQL Server (Transact-SQL) syntax', $systemTexts[0]);
        $this->assertStringContainsString('to 3 decimals at most', $systemTexts[0]);
        $this->assertSame("<database>\n## Database tables\n\n- `store`\n</database>", $systemTexts[1]);
    }

    public function testRefusesADocumentItCannotRead(): void
    {
        $promptManager = $this->createPromptManager($this->createStub(SchemaManager::class), '/missing/instructions.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The assistant document "/missing/instructions.md" cannot be read.');
        $promptManager->getSystemTexts();
    }

    private function createPromptManager(SchemaManager $schemaManager, ?string $instructions): PromptManager
    {
        $queryManager = $this->createStub(QueryManager::class);
        $queryManager->method('getDialect')->willReturn(new SqlServerDialect());
        $queryManager->method('getMaxDecimals')->willReturn(3);

        return new PromptManager(new Translator('en'), $schemaManager, $queryManager, ['instructions' => $instructions, 'dictionary' => null, 'database' => null], [], 'messages', 'en');
    }
}
