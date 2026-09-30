<?php

namespace Tknoweb\AiSqlAssistantBundle\Manager;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Evaluation of the assistant on the test cases of the YAML file set in the "evaluation" configuration of the bundle, through its real entry point AssistantManager.
 * The simulated user answers each question with the next scripted answer of the case, or with the first option offered. Grading is programmatic and never reads a query result:
 * the rows and traces written for the report hold the questions, the texts, the SQL and the costs, but no database value, as agreed with the client.
 * The files follow the layout of the Claude API skill report builder: <variant>/results.jsonl, <variant>/traces/<case>_rep<n>.json, <variant>/errors.jsonl and _state.json.
 */
class EvaluationManager
{
    public const STATUS_OK = 'ok';
    public const STATUS_TRUNCATED = 'truncated';
    public const STATUS_TIMEOUT = 'timeout';

    public const METRICS = [
        ['id' => 'pass', 'label' => 'Pass', 'kind' => 'binary'],
        ['id' => 'clarification', 'label' => 'Clarification', 'kind' => 'binary'],
        ['id' => 'query', 'label' => 'Query', 'kind' => 'binary'],
        ['id' => 'sql', 'label' => 'Expected SQL', 'kind' => 'binary'],
        ['id' => 'output', 'label' => 'Output', 'kind' => 'binary'],
        ['id' => 'referential', 'label' => 'Referential', 'kind' => 'binary'],
        ['id' => 'tools', 'label' => 'Tool budget', 'kind' => 'binary'],
    ];

    private const PERF_FIELDS = [
        ['id' => 'cost_usd', 'label' => 'Cost', 'unit' => '$'],
        ['id' => 'questions', 'label' => 'Questions'],
        ['id' => 'tool_calls', 'label' => 'Tool calls'],
        ['id' => 'latency_s', 'label' => 'Duration', 'unit' => 's'],
    ];

    // Safety net against a case where the assistant keeps asking, the question cap of the engine being per request and the simulated answers never ending a request
    private const MAX_SIMULATED_ANSWERS = 12;

    public function __construct(
        private readonly AssistantManager $assistantManager,
        private readonly PromptManager $promptManager,
        private readonly Filesystem $filesystem,
        #[Autowire('%tknoweb_ai_sql_assistant.evaluation.cases_file%')]
        private readonly ?string $casesFile,
        #[Autowire('%tknoweb_ai_sql_assistant.evaluation.output_directory%')]
        private readonly string $outputDirectory,
    ) {
    }

    public function getOutputDirectory(): string
    {
        return $this->outputDirectory;
    }

    /**
     * Test cases of the YAML file, checked field by field so that a typo fails before any paid call rather than as a silently wrong grade.
     */
    public function getCases(): array
    {
        if (null === $this->casesFile) {
            throw new \LogicException('No test cases file is set in the "evaluation.cases_file" configuration of the assistant.');
        }

        $cases = Yaml::parseFile($this->casesFile);
        $caseIds = [];

        foreach ($cases as $index => $case) {
            $caseId = $case['id'] ?? null;
            if (!is_string($caseId) || '' === $caseId || isset($caseIds[$caseId])) {
                throw new \InvalidArgumentException(sprintf('The test case #%d has a missing or duplicated id.', $index + 1));
            }
            $caseIds[$caseId] = true;

            if (!is_string($case['question'] ?? null) || !is_bool($case['expectQuestion'] ?? null) || !is_bool($case['expectQuery'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('The test case "%s" needs a question, expectQuestion and expectQuery.', $caseId));
            }

            if (isset($case['expectedOutput']) && !in_array($case['expectedOutput'], AssistantManager::OUTPUTS, true)) {
                throw new \InvalidArgumentException(sprintf('The test case "%s" expects an unknown output "%s".', $caseId, $case['expectedOutput']));
            }

            if (isset($case['maxToolCalls']) && (!is_int($case['maxToolCalls']) || $case['maxToolCalls'] < 0)) {
                throw new \InvalidArgumentException(sprintf('The test case "%s" needs a maxToolCalls that is a positive or zero integer.', $caseId));
            }

            foreach ($case['sqlMustContain'] ?? [] as $pattern) {
                if (false === @preg_match($this->getPatternRegex($pattern), '')) {
                    throw new \InvalidArgumentException(sprintf('The test case "%s" holds an invalid SQL pattern "%s".', $caseId, $pattern));
                }
            }
        }

        return $cases;
    }

    /**
     * Run a case through the real entry point of the assistant: its request, then the simulated answers to its questions, until it stops asking or the time limit is reached.
     */
    public function runCase(array $case, string $modelKey, int $timeoutSeconds): array
    {
        $startTime = microtime(true);
        $answers = $case['answers'] ?? [];
        $history = [];
        $events = [];
        $usage = ['models' => [], 'inputTokens' => 0, 'outputTokens' => 0, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0, 'cost' => 0.0];
        $userInput = $case['question'];
        $status = self::STATUS_OK;

        for ($answerCount = 0;; ++$answerCount) {
            $turn = $this->assistantManager->continueConversation($history, $modelKey, $userInput);
            $history = $turn['history'];
            $events = array_merge($events, $turn['events']);
            $usage = $this->addUsage($usage, $turn['usage']);

            if (!$this->assistantManager->isWaitingForAnswer($history) || $answerCount >= self::MAX_SIMULATED_ANSWERS) {
                break;
            }

            if (microtime(true) - $startTime > $timeoutSeconds) {
                $status = self::STATUS_TIMEOUT;

                break;
            }

            $question = end($events);
            $userInput = array_shift($answers) ?? ($question['options'][0] ?? '');
        }

        if (in_array(AssistantManager::EVENT_INTERRUPTED, array_column($events, 'type'), true)) {
            $status = self::STATUS_TRUNCATED;
        }

        return [
            'status' => $status,
            'history' => $history,
            'events' => $events,
            'usage' => $usage,
            'latency' => microtime(true) - $startTime,
        ];
    }

    /**
     * Grade a run against the expectations of its case. Each metric is 1 or 0, a metric the case does not ask about scoring 1, and "pass" requires every one of them.
     * A request the views cannot answer only passes when the assistant ran no query and explained itself, so that a silent assistant does not pass it by doing nothing.
     */
    public function grade(array $case, array $run): array
    {
        $types = array_column($run['events'], 'type');
        $queries = array_values(array_filter($run['events'], fn (array $event) => AssistantManager::EVENT_QUERY === $event['type']));
        $successfulQueries = array_values(array_filter($queries, fn (array $event) => null === $event['error']));
        $lastQuery = end($successfulQueries) ?: null;
        $questionCount = count(array_keys($types, AssistantManager::EVENT_QUESTION, true));
        $toolCallCounts = $this->assistantManager->getToolCallCounts($run['history']);
        $searchCount = $toolCallCounts[AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL] ?? 0;
        $refused = in_array(AssistantManager::EVENT_REFUSAL, $types, true);

        $grade = [
            'clarification' => ($questionCount > 0) === $case['expectQuestion'],
            'query' => $case['expectQuery']
                ? null !== $lastQuery
                : [] === $queries && in_array(AssistantManager::EVENT_TEXT, $types, true),
            'sql' => !$case['expectQuery'] || (null !== $lastQuery && [] === $this->getMissingPatterns($case['sqlMustContain'] ?? [], $lastQuery['sql'])),
            'output' => !$case['expectQuery'] || !isset($case['expectedOutput']) || (null !== $lastQuery && $case['expectedOutput'] === $lastQuery['output']),
            'referential' => !($case['expectReferentialSearch'] ?? false) || $searchCount > 0,
            'tools' => !isset($case['maxToolCalls']) || array_sum($toolCallCounts) <= $case['maxToolCalls'],
        ];
        $grade = ['pass' => !$refused && self::STATUS_OK === $run['status'] && !in_array(false, $grade, true)] + $grade;

        return [
            'grade' => array_map(fn (bool $passed) => $passed ? 1.0 : 0.0, $grade),
            'details' => [
                'questionCount' => $questionCount,
                'queryCount' => count($queries),
                'failedQueryCount' => count($queries) - count($successfulQueries),
                'referentialSearchCount' => $searchCount,
                'refused' => $refused,
                'lastQuery' => null !== $lastQuery ? [
                    'title' => $lastQuery['title'],
                    'interpretation' => $lastQuery['interpretation'],
                    'sql' => $lastQuery['sql'],
                    'output' => $lastQuery['output'],
                    'missingSqlPatterns' => $this->getMissingPatterns($case['sqlMustContain'] ?? [], $lastQuery['sql']),
                ] : null,
            ],
        ];
    }

    /**
     * Keys "<case id>#<rep>" already graded in a variant, so that an interrupted run resumes without running or duplicating them. Failed attempts are not listed and run again.
     */
    public function getCompletedKeys(string $variant): array
    {
        $completedKeys = [];
        foreach ($this->readJsonLines($this->getVariantDirectory($variant).'/results.jsonl') as $row) {
            $completedKeys[$row['prompt_id'].'#'.$row['rep']] = true;
        }

        return $completedKeys;
    }

    /**
     * Write the graded run of a case: its result row, and its trace from the system prompt to the last message. Neither holds any database value.
     */
    public function saveResult(string $variant, array $case, int $rep, array $run, array $grading): void
    {
        $directory = $this->getVariantDirectory($variant);
        $this->writeStateFile();

        $this->filesystem->dumpFile($directory.'/traces/'.$case['id'].'_rep'.$rep.'.json', json_encode(
            array_merge(
                [['role' => 'system', 'content' => implode("\n\n", $this->promptManager->getSystemTexts())]],
                $this->assistantManager->getTranscript($run['history'])
            ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        $this->appendJsonLine($directory.'/results.jsonl', [
            'prompt_id' => $case['id'],
            'rep' => $rep,
            'prompt' => $case['question'],
            'tags' => $case['tags'] ?? [],
            'model' => implode(', ', $run['usage']['models']),
            'status' => $run['status'],
            'grade' => $grading['grade'],
            'usage' => [
                'input_tokens' => $run['usage']['inputTokens'],
                'output_tokens' => $run['usage']['outputTokens'],
                'cache_creation_input_tokens' => $run['usage']['cacheCreationInputTokens'],
                'cache_read_input_tokens' => $run['usage']['cacheReadInputTokens'],
            ],
            'cost_usd' => round($run['usage']['cost'], 6),
            'questions' => $grading['details']['questionCount'],
            'tool_calls' => array_sum($this->assistantManager->getToolCallCounts($run['history'])),
            'latency_s' => round($run['latency'], 1),
            'meta' => $grading['details'],
        ]);
    }

    /**
     * Record an attempt that produced nothing gradable, API error or crash, in the errors sidecar rather than as a failed grade: it is run again on the next resume.
     */
    public function saveError(string $variant, array $case, int $rep, string $failureClass, string $message): void
    {
        $this->appendJsonLine($this->getVariantDirectory($variant).'/errors.jsonl', [
            'prompt_id' => $case['id'],
            'rep' => $rep,
            'failure_class' => $failureClass,
            'message' => $message,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function getVariantDirectory(string $variant): string
    {
        // The report builder only reads the "baseline" and "v<N>" directories
        if (!preg_match('/^(baseline|v\d+)$/', $variant)) {
            throw new \InvalidArgumentException(sprintf('The variant must be "baseline" or "v<N>", "%s" given.', $variant));
        }

        return $this->outputDirectory.'/'.$variant;
    }

    /**
     * Written again at each result, so that a metric added since the first run of the output directory reaches the report.
     */
    private function writeStateFile(): void
    {
        $this->filesystem->dumpFile($this->outputDirectory.'/_state.json', json_encode(['metrics' => self::METRICS, 'perf_fields' => self::PERF_FIELDS], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function getMissingPatterns(array $patterns, string $sql): array
    {
        return array_values(array_filter($patterns, fn (string $pattern) => 1 !== preg_match($this->getPatternRegex($pattern), $sql)));
    }

    private function getPatternRegex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    private function addUsage(array $usage, array $turnUsage): array
    {
        $usage['models'] = array_values(array_unique(array_merge($usage['models'], $turnUsage['models'])));
        foreach (['inputTokens', 'outputTokens', 'cacheCreationInputTokens', 'cacheReadInputTokens', 'cost'] as $key) {
            $usage[$key] += $turnUsage[$key];
        }

        return $usage;
    }

    private function appendJsonLine(string $file, array $row): void
    {
        $this->filesystem->appendToFile($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function readJsonLines(string $file): array
    {
        if (!$this->filesystem->exists($file)) {
            return [];
        }

        return array_map(
            fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", (string) file_get_contents($file)), fn (string $line) => '' !== trim($line))
        );
    }
}
