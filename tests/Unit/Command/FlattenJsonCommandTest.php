<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tknoweb\AiSqlAssistantBundle\Command\FlattenJsonCommand;
use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;

class FlattenJsonCommandTest extends TestCase
{
    public function testReportsTheCountsOfTheRebuild(): void
    {
        $tester = $this->runCommand(['values' => 1200, 'paths' => 35, 'skippedValues' => 2, 'skippedRows' => 1], false);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('1200 values and 35 catalog paths written, 2 values with a too long path and 1 rows without a JSON object left out', $display);
        $this->assertStringNotContainsString('--no-debug', $display);
    }

    public function testWarnsAboutTheDebugMode(): void
    {
        $tester = $this->runCommand(['values' => 0, 'paths' => 0, 'skippedValues' => 0, 'skippedRows' => 0], true);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('--no-debug', $tester->getDisplay());
    }

    public function testFailsWhenARebuildIsAlreadyRunning(): void
    {
        $tester = $this->runCommand(null, false);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('already running', $tester->getDisplay());
    }

    private function runCommand(?array $counts, bool $debug): CommandTester
    {
        $flatteningManager = $this->createMock(JsonFlatteningManager::class);
        $flatteningManager->expects($this->once())->method('rebuild')->willReturn($counts);

        $tester = new CommandTester(new FlattenJsonCommand($flatteningManager, $debug));
        $tester->execute([]);

        return $tester;
    }
}
