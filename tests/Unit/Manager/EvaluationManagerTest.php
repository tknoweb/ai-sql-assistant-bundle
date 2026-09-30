<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\EvaluationManager;
use Tknoweb\AiSqlAssistantBundle\Manager\PromptManager;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;

/**
 * The evaluation of the assistant: the checks of the test cases, the simulated user, the grading and the files of the report, on a scripted provider.
 */
class EvaluationManagerTest extends AssistantManagerTestCase
{
    private const CASE = [
        'id' => 'store-count',
        'question' => 'How many stores?',
        'expectQuestion' => true,
        'expectQuery' => true,
        'sqlMustContain' => ['FROM store', 'COUNT\('],
        'expectedOutput' => 'text',
    ];

    private Filesystem $filesystem;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->directory = sys_get_temp_dir().'/tknoweb_ai_sql_assistant_bundle_tests/evaluation_'.bin2hex(random_bytes(4));
        $this->queryManager->method('execute')->willReturnCallback(fn (string $sql) => str_contains($sql, 'nme')
            ? throw new \InvalidArgumentException("Unknown column 'nme'") : ['columns' => ['n'], 'rows' => [['n' => 3]], 'truncated' => false]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->directory);
    }

    public function testChecksTheTestCasesBeforeAnyPaidCall(): void
    {
        $invalidCases = [
            'The test case #1 has a missing or duplicated id.' => [['question' => 'Q', 'expectQuestion' => false, 'expectQuery' => true]],
            'The test case #2 has a missing or duplicated id.' => [self::CASE, self::CASE],
            'The test case "store-count" needs a question, expectQuestion and expectQuery.' => [['expectQuestion' => 'yes'] + self::CASE],
            'The test case "store-count" expects an unknown output "pdf".' => [['expectedOutput' => 'pdf'] + self::CASE],
            'The test case "store-count" holds an invalid SQL pattern "COUNT(".' => [['sqlMustContain' => ['COUNT(']] + self::CASE],
            'The test case "store-count" needs a maxToolCalls that is a positive or zero integer.' => [['maxToolCalls' => -1] + self::CASE],
        ];

        foreach ($invalidCases as $expectedMessage => $cases) {
            try {
                $this->createEvaluationManager($this->writeCasesFile($cases))->getCases();
                $this->fail(sprintf('Expected "%s".', $expectedMessage));
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        $this->assertSame([self::CASE], $this->createEvaluationManager($this->writeCasesFile([self::CASE]))->getCases());

        $this->expectException(\LogicException::class);
        $this->createEvaluationManager(null)->getCases();
    }

    public function testAnswersTheQuestionsWithTheScriptedAnswersThenTheFirstOption(): void
    {
        $this->provider->queue(
            ScriptedModelProvider::question('toolu_q1', 'Which year?', ['2025', '2024']),
            ScriptedModelProvider::question('toolu_q2', 'Which stores?', ['Open ones', 'All']),
            ScriptedModelProvider::query('toolu_1', 'SELECT COUNT(*) AS n FROM store', ['output' => 'text', 'answer_template' => '{value} stores.']),
            ScriptedModelProvider::text('Here it is.', usage: ['input' => 1000000, 'output' => 0, 'cacheWrite' => 0, 'cacheRead' => 0]),
        );

        $run = $this->createEvaluationManager()->runCase(['answers' => ['2024']] + self::CASE, self::MODEL_KEY, 300);

        $this->assertSame(EvaluationManager::STATUS_OK, $run['status']);
        $answers = array_column(array_filter($run['history'], fn (array $entry) => 'user' === $entry['role'] && is_array($entry['content']) && str_starts_with($entry['content'][0]['toolUseID'], 'toolu_q')), 'content');
        $this->assertSame(['2024', 'Open ones'], array_map(fn (array $content) => $content[0]['content'], $answers));
        $this->assertSame([AssistantManager::EVENT_QUESTION, AssistantManager::EVENT_QUESTION, AssistantManager::EVENT_QUERY, AssistantManager::EVENT_TEXT], $this->getEventTypes($run['events']));
        $this->assertEqualsWithDelta(2.0, $run['usage']['cost'], 1e-9);
        $this->assertSame([self::MODEL_ID], $run['usage']['models']);
    }

    public function testMarksAnInterruptedRunAsTruncated(): void
    {
        $this->provider->queue(ScriptedModelProvider::answer(['Half an ans'], null, ModelMessage::STOP_MAX_TOKENS));

        $this->assertSame(EvaluationManager::STATUS_TRUNCATED, $this->createEvaluationManager()->runCase(self::CASE, self::MODEL_KEY, 300)['status']);
    }

    public function testGradesEveryExpectation(): void
    {
        $evaluationManager = $this->createEvaluationManager();
        $passingRun = $this->runScript($evaluationManager, [
            ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025']),
            ScriptedModelProvider::query('toolu_bad', 'SELECT nme FROM store'),
            ScriptedModelProvider::query('toolu_1', 'SELECT COUNT(*) AS n FROM store', ['output' => 'text', 'answer_template' => '{value} stores.']),
            ScriptedModelProvider::text('Here it is.'),
        ]);

        $grading = $evaluationManager->grade(self::CASE, $passingRun);
        $this->assertSame(['pass' => 1.0, 'clarification' => 1.0, 'query' => 1.0, 'sql' => 1.0, 'output' => 1.0, 'referential' => 1.0, 'tools' => 1.0], $grading['grade']);
        $this->assertSame(1.0, $evaluationManager->grade(['maxToolCalls' => 3] + self::CASE, $passingRun)['grade']['tools'], 'The question and the two queries fit in three calls.');
        $this->assertSame(1, $grading['details']['questionCount']);
        $this->assertSame(2, $grading['details']['queryCount']);
        $this->assertSame(1, $grading['details']['failedQueryCount']);
        $this->assertSame('SELECT COUNT(*) AS n FROM store', $grading['details']['lastQuery']['sql']);
        $this->assertSame([], $grading['details']['lastQuery']['missingSqlPatterns']);

        $failures = [
            'clarification' => ['expectQuestion' => false] + self::CASE,
            'sql' => ['sqlMustContain' => ['FROM employee']] + self::CASE,
            'output' => ['expectedOutput' => 'chart'] + self::CASE,
            'referential' => ['expectReferentialSearch' => true] + self::CASE,
            'query' => ['expectQuery' => false] + self::CASE,
            'tools' => ['maxToolCalls' => 2] + self::CASE,
        ];
        foreach ($failures as $metric => $case) {
            $grade = $evaluationManager->grade($case, $passingRun)['grade'];
            $this->assertSame(0.0, $grade[$metric], $metric);
            $this->assertSame(0.0, $grade['pass'], $metric);
        }

        $this->assertSame(0.0, $evaluationManager->grade(self::CASE, ['status' => EvaluationManager::STATUS_TRUNCATED] + $passingRun)['grade']['pass']);
    }

    public function testPassesARequestTheDatabaseCannotAnswerOnlyWhenTheAssistantExplainsItself(): void
    {
        $evaluationManager = $this->createEvaluationManager();
        $case = ['expectQuestion' => false, 'expectQuery' => false] + self::CASE;

        $explained = $this->runScript($evaluationManager, [ScriptedModelProvider::text('The database does not hold the margins of the orders.')]);
        $this->assertSame(1.0, $evaluationManager->grade($case, $explained)['grade']['pass']);

        $silent = $this->runScript($evaluationManager, [ScriptedModelProvider::answer([], null, ModelMessage::STOP_END)]);
        $this->assertSame(0.0, $evaluationManager->grade($case, $silent)['grade']['query']);

        $refused = $this->runScript($evaluationManager, [ScriptedModelProvider::answer(['No.'], null, ModelMessage::STOP_REFUSAL)]);
        $grading = $evaluationManager->grade($case, $refused);
        $this->assertSame(0.0, $grading['grade']['pass'], 'A refusal never passes.');
        $this->assertTrue($grading['details']['refused']);
    }

    public function testPassesAnOffTopicRequestOnlyWhenTheAssistantAnswersWithoutAnyToolCall(): void
    {
        $evaluationManager = $this->createEvaluationManager();
        $case = ['expectQuestion' => false, 'expectQuery' => false, 'maxToolCalls' => 0] + self::CASE;

        $direct = $this->runScript($evaluationManager, [ScriptedModelProvider::text('I only answer questions about the stores and their employees.')]);
        $this->assertSame(1.0, $evaluationManager->grade($case, $direct)['grade']['pass']);

        $searched = $this->runScript($evaluationManager, [
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store']]),
            ScriptedModelProvider::text('The database holds no recipe.'),
        ]);
        $grade = $evaluationManager->grade($case, $searched)['grade'];
        $this->assertSame(1.0, $grade['query'], 'No query ran, only the tool budget tells the useless search apart.');
        $this->assertSame(0.0, $grade['tools']);
        $this->assertSame(0.0, $grade['pass']);
    }

    public function testWritesTheResultsForTheReportAndResumesAfterThem(): void
    {
        $evaluationManager = $this->createEvaluationManager();
        $run = $this->runScript($evaluationManager, [
            ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025']),
            ScriptedModelProvider::query('toolu_1', 'SELECT COUNT(*) AS n FROM store', ['output' => 'text', 'answer_template' => '{value} stores.']),
            ScriptedModelProvider::text('Here it is.'),
        ]);

        // Left by a run older than the last metric added
        $this->filesystem->dumpFile($this->directory.'/_state.json', json_encode(['metrics' => [], 'perf_fields' => []]));
        $evaluationManager->saveResult('v1', ['tags' => ['precise']] + self::CASE, 0, $run, $evaluationManager->grade(self::CASE, $run));
        $evaluationManager->saveError('v1', self::CASE, 1, 'api_error', 'Overloaded');

        $this->assertSame(['store-count#0' => true], $evaluationManager->getCompletedKeys('v1'), 'A failed attempt runs again on the next resume.');
        $this->assertSame([], $evaluationManager->getCompletedKeys('v2'));

        $state = json_decode(file_get_contents($this->directory.'/_state.json'), true);
        $this->assertSame(EvaluationManager::METRICS, $state['metrics']);

        $row = json_decode(trim(file_get_contents($this->directory.'/v1/results.jsonl')), true);
        $this->assertSame('store-count', $row['prompt_id']);
        $this->assertSame(['precise'], $row['tags']);
        $this->assertSame(1.0, (float) $row['grade']['pass']);
        $this->assertSame(1, $row['questions']);
        $this->assertSame(2, $row['tool_calls']);

        $trace = json_decode(file_get_contents($this->directory.'/v1/traces/store-count_rep0.json'), true);
        $this->assertSame(['role' => 'system', 'content' => implode("\n\n", self::SYSTEM_TEXTS)], $trace[0]);
        $this->assertSame('How many stores?', $trace[1]['content']);

        $error = json_decode(trim(file_get_contents($this->directory.'/v1/errors.jsonl')), true);
        $this->assertSame(['store-count', 1, 'api_error', 'Overloaded'], [$error['prompt_id'], $error['rep'], $error['failure_class'], $error['message']]);

        // No stored value in anything written for the report
        foreach (['v1/results.jsonl', 'v1/traces/store-count_rep0.json'] as $file) {
            $this->assertStringNotContainsString('3 stores', file_get_contents($this->directory.'/'.$file), $file);
        }

        $this->expectException(\InvalidArgumentException::class);
        $evaluationManager->getCompletedKeys('../secrets');
    }

    private function runScript(EvaluationManager $evaluationManager, array $answers): array
    {
        $this->provider->queue(...$answers);

        return $evaluationManager->runCase(self::CASE, self::MODEL_KEY, 300);
    }

    private function writeCasesFile(array $cases): string
    {
        $file = $this->directory.'/cases.yaml';
        $this->filesystem->dumpFile($file, Yaml::dump($cases, 4));

        return $file;
    }

    private function createEvaluationManager(?string $casesFile = 'unused.yaml'): EvaluationManager
    {
        $promptManager = $this->createStub(PromptManager::class);
        $promptManager->method('getSystemTexts')->willReturn(self::SYSTEM_TEXTS);

        return new EvaluationManager($this->createAssistantManager(), $promptManager, $this->filesystem, $casesFile, $this->directory);
    }
}
