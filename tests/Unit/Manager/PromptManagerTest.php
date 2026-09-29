<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Tknoweb\AiSqlAssistantBundle\Manager\PromptManager;
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

        $systemTexts = (new PromptManager(new Translator('en'), $schemaManager, ['instructions' => null, 'dictionary' => null, 'database' => null], [], 'messages', 'en'))->getSystemTexts();

        $this->assertCount(2, $systemTexts);
        $this->assertSame(trim(file_get_contents(dirname(__DIR__, 3).'/resources/prompt/instructions.md')), $systemTexts[0]);
        $this->assertSame("<database>\n## Database tables\n\n- `store`\n</database>", $systemTexts[1]);
    }

    public function testRefusesADocumentItCannotRead(): void
    {
        $promptManager = new PromptManager(new Translator('en'), $this->createStub(SchemaManager::class), ['instructions' => '/missing/instructions.md', 'dictionary' => null, 'database' => null], [], 'messages', 'en');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The assistant document "/missing/instructions.md" cannot be read.');
        $promptManager->getSystemTexts();
    }
}
