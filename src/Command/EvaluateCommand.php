<?php

namespace Tknoweb\AiSqlAssistantBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\EvaluationManager;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;

/**
 * Run the test cases of the assistant against the model API, one paid conversation per case and rep, and write the graded results for the report builder.
 * Already graded cases are skipped, so that an interrupted run resumes where it stopped. --dry-run lists the cases without calling the API.
 */
#[AsCommand(name: 'ai-sql-assistant:evaluate', description: 'Run the test cases of the assistant against the model API (paid calls)')]
class EvaluateCommand extends Command
{
    public function __construct(
        private readonly EvaluationManager $evaluationManager,
        private readonly AssistantManager $assistantManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'Result directory: "baseline", or "v1", "v2"... after a change', 'baseline')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Key of the model in the "models" configuration, the default model when not given')
            ->addOption('reps', null, InputOption::VALUE_REQUIRED, 'Runs of each case', '1')
            ->addOption('case', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only run these case ids')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Time limit of a case in seconds, checked between two turns', '300')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the cases to run without calling the API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $variant = (string) $input->getOption('variant');
        $modelKey = (string) ($input->getOption('model') ?? $this->assistantManager->getDefaultModelKey());
        $reps = max(1, (int) $input->getOption('reps'));
        $timeoutSeconds = max(1, (int) $input->getOption('timeout'));

        if (!in_array($modelKey, $this->assistantManager->getModelKeys(), true)) {
            $io->error(sprintf('Unknown model "%s", expected one of: %s.', $modelKey, implode(', ', $this->assistantManager->getModelKeys())));

            return Command::INVALID;
        }

        $cases = $this->evaluationManager->getCases();
        $caseIds = $input->getOption('case');
        if ([] !== $caseIds) {
            $cases = array_values(array_filter($cases, fn (array $case) => in_array($case['id'], $caseIds, true)));
        }

        $completedKeys = $this->evaluationManager->getCompletedKeys($variant);
        $pendingRuns = [];
        for ($rep = 0; $rep < $reps; ++$rep) {
            foreach ($cases as $case) {
                if (!isset($completedKeys[$case['id'].'#'.$rep])) {
                    $pendingRuns[] = [$case, $rep];
                }
            }
        }

        $io->text(sprintf('%d case(s) x %d rep(s) on "%s", %d already graded in "%s", %d to run.', count($cases), $reps, $modelKey, count($cases) * $reps - count($pendingRuns), $variant, count($pendingRuns)));

        if ($input->getOption('dry-run')) {
            foreach ($pendingRuns as [$case, $rep]) {
                $io->text(sprintf('- %s (rep %d): %s', $case['id'], $rep, $case['question']));
            }

            return Command::SUCCESS;
        }

        $passCount = 0;
        $gradedCount = 0;
        $totalCost = 0.0;
        foreach ($pendingRuns as [$case, $rep]) {
            try {
                $run = $this->evaluationManager->runCase($case, $modelKey, $timeoutSeconds);
            } catch (ModelProviderException $exception) {
                $this->evaluationManager->saveError($variant, $case, $rep, 'api_error', $exception->getMessage());
                $io->text(sprintf('<error>ERROR</error> %s (rep %d): %s', $case['id'], $rep, $exception->getMessage()));

                continue;
            }

            $totalCost += $run['usage']['cost'];

            // A timed out case has no conversation end to grade: it goes to the errors sidecar and runs again on the next resume
            if (EvaluationManager::STATUS_TIMEOUT === $run['status']) {
                $this->evaluationManager->saveError($variant, $case, $rep, 'timeout', sprintf('No end of conversation after %d seconds.', $timeoutSeconds));
                $io->text(sprintf('<error>TIMEOUT</error> %s (rep %d)', $case['id'], $rep));

                continue;
            }

            $grading = $this->evaluationManager->grade($case, $run);
            $this->evaluationManager->saveResult($variant, $case, $rep, $run, $grading);

            ++$gradedCount;
            $passed = 1.0 === $grading['grade']['pass'];
            $passCount += $passed ? 1 : 0;
            $failedMetrics = array_keys(array_filter($grading['grade'], fn (float $score, string $metric) => 'pass' !== $metric && 1.0 !== $score, ARRAY_FILTER_USE_BOTH));

            $io->text(sprintf(
                '%s %s (rep %d): %d question(s), %s, %.4f $%s',
                $passed ? '<info>PASS</info>' : '<comment>FAIL</comment>',
                $case['id'],
                $rep,
                $grading['details']['questionCount'],
                $run['status'],
                $run['usage']['cost'],
                [] !== $failedMetrics ? ', failed: '.implode(', ', $failedMetrics) : ''
            ));
        }

        if ($gradedCount > 0) {
            // Normal approximation of the 95% interval of the pass rate, rough on a few cases but enough to tell signal from noise
            $passRate = $passCount / $gradedCount;
            $margin = 1.96 * sqrt($passRate * (1 - $passRate) / $gradedCount);
            $io->success(sprintf('%d/%d passed (%.0f%% +/- %.0f points), %.4f $ spent on this run. Results in %s/%s.', $passCount, $gradedCount, $passRate * 100, $margin * 100, $totalCost, $this->evaluationManager->getOutputDirectory(), $variant));
        }

        return Command::SUCCESS;
    }
}
