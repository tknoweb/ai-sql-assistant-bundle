<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;

/**
 * The evaluation command on the test cases of the test application, the scripted provider standing for the paid API.
 */
class EvaluateCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private string $outputDirectory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->createDefaultStores();
        $this->outputDirectory = static::getContainer()->getParameter('tknoweb_ai_sql_assistant.evaluation.output_directory');
        (new Filesystem())->remove($this->outputDirectory);
        $this->tester = new CommandTester((new Application(static::$kernel))->find('ai-sql-assistant:evaluate'));
    }

    public function testListsTheCasesWithoutCallingTheApi(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('2 case(s) x 1 rep(s) on "scripted", 0 already graded in "baseline", 2 to run.', $display);
        $this->assertStringContainsString('- store-count (rep 0): How many open stores?', $display);
        $this->assertCount(0, $this->getProvider()->calls);
    }

    public function testRefusesAnUnknownModel(): void
    {
        $this->tester->execute(['--model' => 'unknown']);

        $this->assertSame(Command::INVALID, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown model "unknown", expected one of: scripted, other.', $this->tester->getDisplay());
    }

    public function testGradesTheCasesAndResumesAfterTheGradedOnes(): void
    {
        $this->getProvider()->queue(
            ScriptedModelProvider::query('toolu_1', "SELECT COUNT(*) AS n FROM store WHERE status = 'open'", ['output' => 'text', 'answer_template' => '{value} open stores.']),
            ScriptedModelProvider::text('Anything else?'),
            new ModelProviderException('The model API failed: overloaded'),
        );

        $this->tester->execute(['--variant' => 'v1']);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        $this->assertStringContainsString('PASS store-count (rep 0): 0 question(s), ok', $display);
        $this->assertStringContainsString('ERROR employee-count (rep 0): The model API failed: overloaded', $display);
        $this->assertStringContainsString('1/1 passed', $display);
        $this->assertFileExists($this->outputDirectory.'/v1/results.jsonl');
        $this->assertFileExists($this->outputDirectory.'/v1/errors.jsonl');
        $this->assertStringNotContainsString('2 open stores', file_get_contents($this->outputDirectory.'/v1/results.jsonl'));

        // Only the case that failed runs again
        $this->getProvider()->queue(
            ScriptedModelProvider::question('toolu_q', 'Which employees?', ['Active ones', 'Every employee']),
            ScriptedModelProvider::query('toolu_2', 'SELECT COUNT(*) AS n FROM employee'),
            ScriptedModelProvider::text('Done'),
        );
        $this->tester->execute(['--variant' => 'v1', '--case' => ['employee-count']]);

        $display = preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        $this->assertStringContainsString('1 case(s) x 1 rep(s) on "scripted", 0 already graded in "v1", 1 to run.', $display);
        $this->assertStringContainsString('PASS employee-count (rep 0): 1 question(s), ok', $display);
        $this->assertSame('Every employee', $this->getProvider()->calls[4]['history'][2]['content'][0]['content'], 'The scripted answer of the case.');

        $this->tester->execute(['--variant' => 'v1', '--dry-run' => true]);
        $this->assertStringContainsString('2 already graded in "v1", 0 to run.', $this->tester->getDisplay());
    }
}
