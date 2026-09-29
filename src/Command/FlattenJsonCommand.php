<?php

namespace Tknoweb\AiSqlAssistantBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;

/**
 * Rebuild the flattened JSON values the assistant queries, and their catalog, meant to run every night from the server crontab.
 */
#[AsCommand(name: 'ai-sql-assistant:flatten-json', description: 'Rebuild the flattened JSON values queried by the assistant')]
class FlattenJsonCommand extends Command
{
    public function __construct(
        private readonly JsonFlatteningManager $flatteningManager,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // In debug mode, the Doctrine profiler keeps every query of the process, which is millions of inserts here
        if ($this->debug) {
            $io->warning('The debug mode keeps every query in memory: run this command with --no-debug.');
        }

        $startTime = microtime(true);
        $counts = $this->flatteningManager->rebuild();
        if (null === $counts) {
            $io->error('The flattening of the JSON values is already running.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d values and %d catalog paths written, %d values with a too long path and %d rows without a JSON object left out, in %d s with %d MB of memory at most.',
            $counts['values'],
            $counts['paths'],
            $counts['skippedValues'],
            $counts['skippedRows'],
            microtime(true) - $startTime,
            memory_get_peak_usage(true) / 1048576
        ));

        return Command::SUCCESS;
    }
}
