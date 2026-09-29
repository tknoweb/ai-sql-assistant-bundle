<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelMessage;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\PublicReferentialProvider;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;

/**
 * The conversation loop, whatever the provider: tools, questions, limits, history, timeline and cost. The provider is scripted, the query manager a test double.
 */
class AssistantManagerTest extends AssistantManagerTestCase
{
    private const STORED_VALUE = 'S3CR3T-STORED-VALUE';
    private const QUERY_SUCCESS_RESULT = 'The query ran successfully. Its result is displayed to the user, you cannot see it.';

    public function testAQueryResultNeverReachesTheModel(): void
    {
        $this->queryManager = $this->createMock(QueryManager::class);
        $this->queryManager->expects($this->once())->method('execute')
            ->with('SELECT name FROM store', AssistantManager::MAX_DISPLAYED_ROWS)
            ->willReturn(['columns' => ['name'], 'rows' => [['name' => self::STORED_VALUE]], 'truncated' => false]);
        $this->provider->queue(
            ScriptedModelProvider::query('toolu_1', 'SELECT name FROM store', ['output' => 'text', 'answer_template' => 'The store is {value}.']),
            ScriptedModelProvider::text('Do you want another figure?'),
        );

        $turn = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Which store?');

        // The value is displayed to the user...
        $this->assertSame([AssistantManager::EVENT_QUERY, AssistantManager::EVENT_TEXT], $this->getEventTypes($turn['events']));
        $this->assertSame('The store is '.self::STORED_VALUE.'.', $turn['events'][0]['answer']);
        $this->assertSame([['name' => self::STORED_VALUE]], $turn['events'][0]['result']['rows']);

        // ...but neither sent to the model nor stored in the history
        $this->assertStringNotContainsString(self::STORED_VALUE, json_encode($this->provider->calls, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::STORED_VALUE, json_encode($turn['history'], JSON_THROW_ON_ERROR));
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => self::QUERY_SUCCESS_RESULT]], $turn['history'][2]['content']);
    }

    public function testStoresEachModelMessageWithItsProvider(): void
    {
        $this->provider->queue(ScriptedModelProvider::text('Hello'));

        $turn = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Hi');

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'provider' => 'scripted', 'message' => ScriptedModelProvider::text('Hello')],
        ], $turn['history']);
        $this->assertSame([['type' => AssistantManager::EVENT_TEXT, 'text' => 'Hello']], $turn['events']);
        $this->assertSame(self::MODEL_ID, $this->provider->calls[0]['model']);
        $this->assertSame(16000, $this->provider->calls[0]['maxTokens']);
    }

    public function testSendsTheSameSystemPromptAndToolsOnEveryCall(): void
    {
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store']]),
            ScriptedModelProvider::text('Done'),
        );
        $this->schemaManager->method('describeTables')->willReturn('## store');

        $assistantManager = $this->createAssistantManager(new PublicReferentialProvider());
        $turn = $assistantManager->continueConversation([], self::MODEL_KEY, 'First');
        $this->provider->queue(ScriptedModelProvider::text('Again'));
        $assistantManager->continueConversation($turn['history'], self::MODEL_KEY, 'Second');

        $this->assertCount(3, $this->provider->calls);
        foreach ($this->provider->calls as $call) {
            $this->assertSame(self::SYSTEM_TEXTS, $call['systemTexts']);
            $this->assertSame($this->provider->calls[0]['tools'], $call['tools']);
        }
    }

    public function testDeclaresStrictToolSchemasInAFixedOrder(): void
    {
        $this->provider->queue(ScriptedModelProvider::text('Hello'));
        $this->createAssistantManager(new PublicReferentialProvider())->continueConversation([], self::MODEL_KEY, 'Hi');
        $tools = $this->provider->calls[0]['tools'];

        $this->assertSame(
            [AssistantManager::TOOL_ASK_USER, AssistantManager::TOOL_RUN_QUERY, AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, AssistantManager::TOOL_DESCRIBE_TABLES, AssistantManager::TOOL_SEARCH_DOCUMENT_FIELDS],
            array_column($tools, 'name')
        );

        // Strict mode requires every property of every object to be required, and no other property
        $objectSchemas = [];
        foreach ($tools as $tool) {
            $this->assertTrue($tool['strict'], $tool['name']);
            $this->assertNotSame('', $tool['description'], $tool['name']);
            $objectSchemas[$tool['name']] = $tool['inputSchema'];
        }
        $objectSchemas['run_query.chart'] = $objectSchemas[AssistantManager::TOOL_RUN_QUERY]['properties']['chart']['anyOf'][0];
        foreach ($objectSchemas as $name => $schema) {
            $this->assertSame('object', $schema['type'], $name);
            $this->assertSame(array_keys($schema['properties']), $schema['required'], $name);
            $this->assertFalse($schema['additionalProperties'], $name);
        }

        $referentialTool = $tools[2];
        $this->assertSame(['city'], $referentialTool['inputSchema']['properties']['referential']['enum']);
        $this->assertStringContainsString('The city referential lists the cities of the stores.', $referentialTool['description']);
        $this->assertStringContainsString('json_value', $tools[4]['description']);
    }

    public function testOffersNoReferentialToolWithoutAReferentialProvider(): void
    {
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, ['referential' => 'city', 'texts' => ['Ly']]),
            ScriptedModelProvider::text('Sorry'),
        );

        $turn = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Stores of Lyon');

        $this->assertNotContains(AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, array_column($this->provider->calls[0]['tools'], 'name'));
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => 'No public referential is available.', 'isError' => true]], $turn['history'][2]['content']);
    }

    public function testSearchesSeveralNamesOfAPublicReferentialInASingleCall(): void
    {
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, ['referential' => 'city', 'texts' => ['l', 'Par', 'l']]),
            // A refused name only reports its error, the call only failing when every search does
            ScriptedModelProvider::toolCall('toolu_2', AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, ['referential' => 'city', 'texts' => ['Ly', ' ']]),
            ScriptedModelProvider::toolCall('toolu_3', AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, ['referential' => 'country', 'texts' => ['France']]),
            ScriptedModelProvider::toolCall('toolu_4', AssistantManager::TOOL_SEARCH_PUBLIC_REFERENTIAL, ['referential' => 'city', 'texts' => array_map(fn (int $index) => 'City '.$index, range(1, 21))]),
            ScriptedModelProvider::text('Found'),
        );

        $turn = $this->createAssistantManager(new PublicReferentialProvider())->continueConversation([], self::MODEL_KEY, 'Stores of Lyon and Paris');

        $this->assertSame(
            [['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => '[{"text":"l","found":["Lyon","Lille"]},{"text":"Par","found":["Paris"]}]']],
            $turn['history'][2]['content']
        );
        $this->assertSame(
            [['type' => 'tool_result', 'toolUseID' => 'toolu_2', 'content' => '[{"text":"Ly","found":["Lyon"]},{"text":" ","error":"Unknown referential or empty text."}]']],
            $turn['history'][4]['content']
        );
        $this->assertSame(
            [['type' => 'tool_result', 'toolUseID' => 'toolu_3', 'content' => '[{"text":"France","error":"Unknown referential or empty text."}]', 'isError' => true]],
            $turn['history'][6]['content']
        );
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_4', 'content' => 'Give between 1 and 20 names to search.', 'isError' => true]], $turn['history'][8]['content']);
        $this->assertSame([AssistantManager::EVENT_TEXT], $this->getEventTypes($turn['events']));
    }

    public function testDescribesBetweenOneAndTenTables(): void
    {
        $this->schemaManager = $this->createMock(SchemaManager::class);
        $this->schemaManager->expects($this->once())->method('describeTables')->with(['store', 'employee'])->willReturn('## store');
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => []]),
            ScriptedModelProvider::toolCall('toolu_2', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => array_map(fn (int $index) => 'table'.$index, range(1, 11))]),
            ScriptedModelProvider::toolCall('toolu_3', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store', 'employee', 'store']]),
            ScriptedModelProvider::text('Done'),
        );

        $history = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Tables?')['history'];

        $this->assertSame('Give between 1 and 10 table names.', $history[2]['content'][0]['content']);
        $this->assertTrue($history[2]['content'][0]['isError']);
        $this->assertSame('Give between 1 and 10 table names.', $history[4]['content'][0]['content']);
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_3', 'content' => '## store']], $history[6]['content']);
    }

    public function testSearchesTheDocumentFields(): void
    {
        $this->catalogManager->method('search')->willReturnCallback(fn (string $text) => 'x' === $text
            ? throw new \InvalidArgumentException('The searched text must hold at least one word of two characters.') : ['results' => [['source' => 'document.content', 'generic_paths' => ['price']]], 'labels' => [], 'truncated' => false]);
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_SEARCH_DOCUMENT_FIELDS, ['text' => 'price']),
            ScriptedModelProvider::toolCall('toolu_2', AssistantManager::TOOL_SEARCH_DOCUMENT_FIELDS, ['text' => 'x']),
            ScriptedModelProvider::text('Done'),
        );

        $history = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Median price?')['history'];

        $this->assertSame('{"results":[{"source":"document.content","generic_paths":["price"]}],"labels":[],"truncated":false}', $history[2]['content'][0]['content']);
        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_2', 'content' => 'The searched text must hold at least one word of two characters.', 'isError' => true]], $history[4]['content']);
    }

    public function testReportsAnUnknownToolToTheModel(): void
    {
        $this->provider->queue(ScriptedModelProvider::toolCall('toolu_1', 'drop_database', []), ScriptedModelProvider::text('Sorry'));

        $history = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Hi')['history'];

        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => 'Unknown tool "drop_database".', 'isError' => true]], $history[2]['content']);
    }

    public function testSendsAFailedQueryBackToTheModelWithItsError(): void
    {
        $this->queryManager->method('execute')->willThrowException(new \InvalidArgumentException("Unknown column 'nme'"));
        $this->provider->queue(ScriptedModelProvider::query('toolu_1', 'SELECT nme FROM store'), ScriptedModelProvider::text('I could not.'));

        $turn = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Names?');

        $this->assertSame([['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => "The query failed: Unknown column 'nme'", 'isError' => true]], $turn['history'][2]['content']);
        $this->assertSame("Unknown column 'nme'", $turn['events'][0]['error']);
        $this->assertNull($turn['events'][0]['result']);
        $this->assertNull($turn['events'][0]['output']);
    }

    public function testAsksAQuestionAndTakesTheNextInputAsItsAnswer(): void
    {
        $assistantManager = $this->createAssistantManager();
        $this->provider->queue(ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025', 2024]));

        $turn = $assistantManager->continueConversation([], self::MODEL_KEY, 'How many stores?');

        $this->assertSame([['type' => AssistantManager::EVENT_QUESTION, 'toolUseId' => 'toolu_q', 'question' => 'Which year?', 'options' => ['2025', '2024']]], $turn['events']);
        $this->assertTrue($assistantManager->isWaitingForAnswer($turn['history']));
        $this->assertCount(2, $turn['history'], 'The pending question stays without a result until the user answers it.');

        $this->provider->queue(ScriptedModelProvider::text('Thanks'));
        $turn = $assistantManager->continueConversation($turn['history'], self::MODEL_KEY, '2025');

        $this->assertSame(['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'toolu_q', 'content' => '2025']]], $turn['history'][2]);
        $this->assertFalse($assistantManager->isWaitingForAnswer($turn['history']));
        $this->assertSame(1, $assistantManager->getRequestQuestionCount($turn['history']));
    }

    public function testRefusesTheQuestionsBeyondTheLimitOfARequest(): void
    {
        $assistantManager = $this->createAssistantManager();
        $this->provider->queue(ScriptedModelProvider::question('toolu_q1', 'Question 1?', ['Yes', 'No']));
        $history = $assistantManager->continueConversation([], self::MODEL_KEY, 'Vague request')['history'];

        for ($index = 2; $index <= AssistantManager::MAX_QUESTIONS_PER_REQUEST; ++$index) {
            $this->provider->queue(ScriptedModelProvider::question('toolu_q'.$index, 'Question '.$index.'?', ['Yes', 'No']));
            $turn = $assistantManager->continueConversation($history, self::MODEL_KEY, 'Yes');
            $history = $turn['history'];
            $this->assertSame(AssistantManager::EVENT_QUESTION, $turn['events'][0]['type']);
        }

        // The eleventh question is refused without being shown, and the model goes on with the default rules
        $this->provider->queue(ScriptedModelProvider::question('toolu_q11', 'Question 11?', ['Yes', 'No']), ScriptedModelProvider::text('I applied the default rules.'));
        $turn = $assistantManager->continueConversation($history, self::MODEL_KEY, 'Yes');

        $this->assertSame([['type' => AssistantManager::EVENT_TEXT, 'text' => 'I applied the default rules.']], $turn['events']);
        $refusal = $turn['history'][count($turn['history']) - 2]['content'][0];
        $this->assertSame('toolu_q11', $refusal['toolUseID']);
        $this->assertTrue($refusal['isError']);
        $this->assertStringContainsString('question limit', $refusal['content']);
        $this->assertSame(11, $assistantManager->getRequestQuestionCount($turn['history']));

        $timelineQuestions = array_filter($assistantManager->getTimeline($turn['history']), fn (array $event) => AssistantManager::EVENT_QUESTION === $event['type']);
        $this->assertCount(AssistantManager::MAX_QUESTIONS_PER_REQUEST, $timelineQuestions);

        // A new request starts a new count
        $this->provider->queue(ScriptedModelProvider::question('toolu_q12', 'Question?', ['Yes', 'No']));
        $turn = $assistantManager->continueConversation($turn['history'], self::MODEL_KEY, 'Another request');
        $this->assertSame(AssistantManager::EVENT_QUESTION, $turn['events'][0]['type']);
        $this->assertSame(1, $assistantManager->getRequestQuestionCount($turn['history']));
    }

    public function testStopsAModelLoopingOnItsTools(): void
    {
        $this->schemaManager->method('describeTables')->willReturn('## store');
        for ($index = 1; $index <= 20; ++$index) {
            $this->provider->queue(ScriptedModelProvider::toolCall('toolu_'.$index, AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store']]));
        }

        $assistantManager = $this->createAssistantManager();
        $turn = $assistantManager->continueConversation([], self::MODEL_KEY, 'Loop');

        $this->assertCount(12, $this->provider->calls);
        $this->assertSame([['type' => AssistantManager::EVENT_INTERRUPTED]], $turn['events']);

        // The call the turn stopped before running gets an error result, so that the conversation can go on
        $this->assertSame(['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'toolUseID' => 'toolu_12',
            'content' => 'This tool call was not run, the turn was interrupted before it.',
            'isError' => true,
        ]]], end($turn['history']));
        $this->assertFalse($assistantManager->isWaitingForAnswer($turn['history']));
        $this->assertSame([AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_INTERRUPTED], $this->getEventTypes($assistantManager->getTimeline($turn['history'])));
    }

    public function testReportsARefusalAndATruncatedAnswer(): void
    {
        // A truncated tool call is never run
        $this->queryManager = $this->createMock(QueryManager::class);
        $this->queryManager->expects($this->never())->method('execute');
        $assistantManager = $this->createAssistantManager();
        $this->provider->queue(
            ScriptedModelProvider::answer(['I cannot help with that.'], null, ModelMessage::STOP_REFUSAL),
            ScriptedModelProvider::answer(['Half an ans'], null, ModelMessage::STOP_MAX_TOKENS),
            ScriptedModelProvider::answer([], ['id' => 'toolu_cut', 'name' => AssistantManager::TOOL_RUN_QUERY, 'input' => ['sql' => 'SELECT']], ModelMessage::STOP_MAX_TOKENS),
        );

        $turn = $assistantManager->continueConversation([], self::MODEL_KEY, 'Forbidden request');
        $this->assertSame([AssistantManager::EVENT_TEXT, AssistantManager::EVENT_REFUSAL], $this->getEventTypes($turn['events']));

        $turn = $assistantManager->continueConversation($turn['history'], self::MODEL_KEY, 'Long request');
        $this->assertSame([AssistantManager::EVENT_TEXT, AssistantManager::EVENT_INTERRUPTED], $this->getEventTypes($turn['events']));

        // The truncated call gets its error result, and is left out of the timeline as it was of the turn
        $turn = $assistantManager->continueConversation($turn['history'], self::MODEL_KEY, 'Query request');
        $this->assertSame([AssistantManager::EVENT_INTERRUPTED], $this->getEventTypes($turn['events']));
        $this->assertSame('toolu_cut', end($turn['history'])['content'][0]['toolUseID']);

        $this->assertSame([
            AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_TEXT, AssistantManager::EVENT_REFUSAL,
            AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_TEXT, AssistantManager::EVENT_INTERRUPTED,
            AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_INTERRUPTED,
        ], $this->getEventTypes($assistantManager->getTimeline($turn['history'])));
    }

    public function testClosesATruncatedQuestionInsteadOfWaitingForItsAnswer(): void
    {
        $assistantManager = $this->createAssistantManager();
        $this->provider->queue(ScriptedModelProvider::answer([], ['id' => 'toolu_q', 'name' => AssistantManager::TOOL_ASK_USER, 'input' => ['question' => 'Which ye']], ModelMessage::STOP_MAX_TOKENS));

        $turn = $assistantManager->continueConversation([], self::MODEL_KEY, 'How many?');

        $this->assertSame([AssistantManager::EVENT_INTERRUPTED], $this->getEventTypes($turn['events']));
        $this->assertFalse($assistantManager->isWaitingForAnswer($turn['history']));
        $this->assertSame([AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_INTERRUPTED], $this->getEventTypes($assistantManager->getTimeline($turn['history'])));
    }

    public function testComputesTheCostFromThePricesOfTheAnsweringModel(): void
    {
        $this->provider->queue(
            ScriptedModelProvider::toolCall('toolu_1', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store']], usage: ['input' => 100, 'output' => 20, 'cacheWrite' => 1000, 'cacheRead' => 0]),
            // A fallback model with prices of its own, then an unknown one, billed at the prices of the requested model
            ScriptedModelProvider::toolCall('toolu_2', AssistantManager::TOOL_DESCRIBE_TABLES, ['tables' => ['store']], model: 'fallback-model', usage: ['input' => 10, 'output' => 30, 'cacheWrite' => 0, 'cacheRead' => 1000]),
            ScriptedModelProvider::text('Done', 'unknown-model', ['input' => 5, 'output' => 10, 'cacheWrite' => 0, 'cacheRead' => 1100]),
        );
        $this->schemaManager->method('describeTables')->willReturn('## store');

        $usage = $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Hi')['usage'];

        $this->assertSame([self::MODEL_ID, 'fallback-model', 'unknown-model'], $usage['models']);
        $this->assertSame(115, $usage['inputTokens']);
        $this->assertSame(60, $usage['outputTokens']);
        $this->assertSame(1000, $usage['cacheCreationInputTokens']);
        $this->assertSame(2100, $usage['cacheReadInputTokens']);
        $expectedCost = (100 * 2.0 + 1000 * 2.5 + 20 * 10.0) + (10 * 1.0 + 1000 * 0.1 + 30 * 5.0) + (5 * 2.0 + 1100 * 0.2 + 10 * 10.0);
        $this->assertEqualsWithDelta($expectedCost / 1000000, $usage['cost'], 1e-12);
    }

    public function testRecordsTheRequestedModelWhenTheProviderGivesNone(): void
    {
        $this->provider->queue(ScriptedModelProvider::text('Hello', ''));

        $this->assertSame([self::MODEL_ID], $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Hi')['usage']['models']);
    }

    public function testRefusesAModelWithoutPrices(): void
    {
        $this->provider->queue(ScriptedModelProvider::text('Hello', 'unknown-model'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('The prices of the model "%s" are missing', self::MODEL_ID));
        $this->createAssistantManager(prices: [])->continueConversation([], self::MODEL_KEY, 'Hi');
    }

    public function testRefusesAnUnknownModelOrProvider(): void
    {
        $assistantManager = $this->createAssistantManager(models: ['orphan' => ['provider' => 'missing', 'id' => 'orphan-model']]);

        try {
            $assistantManager->continueConversation([], 'unknown', 'Hi');
            $this->fail('An unknown model must be refused.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Unknown assistant model "unknown".', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The provider "missing" is missing from the "providers" configuration of the assistant.');
        $assistantManager->continueConversation([], 'orphan', 'Hi');
    }

    public function testChecksTheDefaultModel(): void
    {
        $this->assertSame(self::MODEL_KEY, $this->createAssistantManager()->getDefaultModelKey());
        $this->assertSame([self::MODEL_KEY], $this->createAssistantManager()->getModelKeys());

        $this->expectException(\LogicException::class);
        $this->createAssistantManager(defaultModelKey: 'missing')->getDefaultModelKey();
    }

    public function testLetsTheProviderExceptionEndTheTurn(): void
    {
        $this->provider->queue(new ModelProviderException('The model API failed: overloaded'));

        $this->expectException(ModelProviderException::class);
        $this->createAssistantManager()->continueConversation([], self::MODEL_KEY, 'Hi');
    }

    public function testReadsEachMessageWithTheProviderThatWroteIt(): void
    {
        $otherProvider = new ScriptedModelProvider();
        $assistantManager = $this->createAssistantManager(providers: ['other' => $otherProvider], models: ['alternative' => ['provider' => 'other', 'id' => 'other-model']], prices: self::PRICES + ['other-model' => self::PRICES[self::MODEL_ID]]);
        $otherProvider->queue(ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025']));

        $turn = $assistantManager->continueConversation([], 'alternative', 'How many?');

        $this->assertSame('other', $turn['history'][1]['provider']);
        $this->assertCount(0, $this->provider->calls);
        $this->assertTrue($assistantManager->isWaitingForAnswer($turn['history']));
    }

    public function testReadsALegacyHistoryWithTheProviderOfTheDefaultModel(): void
    {
        $assistantManager = $this->createAssistantManager();
        $this->provider->queue(ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025']));
        $history = $assistantManager->continueConversation([], self::MODEL_KEY, 'How many?')['history'];

        // Histories stored before the providers were recorded
        unset($history[1]['provider']);

        $this->assertTrue($assistantManager->isWaitingForAnswer($history));
        $this->assertSame([AssistantManager::EVENT_USER_MESSAGE, AssistantManager::EVENT_QUESTION], $this->getEventTypes($assistantManager->getTimeline($history)));
    }

    public function testRebuildsTheTimelineOfAStoredConversation(): void
    {
        $this->queryManager->method('execute')->willReturnCallback(fn (string $sql) => str_contains($sql, 'nme')
            ? throw new \InvalidArgumentException("Unknown column 'nme'") : ['columns' => ['n'], 'rows' => [['n' => 3]], 'truncated' => false]);
        $assistantManager = $this->createAssistantManager();

        $this->provider->queue(ScriptedModelProvider::question('toolu_q', 'Which year?', ['2025', '2024']));
        $history = $assistantManager->continueConversation([], self::MODEL_KEY, 'How many stores?')['history'];
        $this->provider->queue(
            ScriptedModelProvider::query('toolu_bad', 'SELECT nme FROM store', ['title' => 'Bad']),
            ScriptedModelProvider::query('toolu_good', 'SELECT COUNT(*) AS n FROM store', ['title' => 'Stores', 'interpretation' => 'I counted the stores of 2025.']),
            ScriptedModelProvider::text('There they are.'),
        );
        $history = $assistantManager->continueConversation($history, self::MODEL_KEY, '2025')['history'];

        $this->assertSame([
            ['type' => AssistantManager::EVENT_USER_MESSAGE, 'text' => 'How many stores?'],
            ['type' => AssistantManager::EVENT_QUESTION, 'toolUseId' => 'toolu_q', 'question' => 'Which year?', 'options' => ['2025', '2024']],
            ['type' => AssistantManager::EVENT_USER_MESSAGE, 'text' => '2025'],
            ['type' => AssistantManager::EVENT_QUERY, 'toolUseId' => 'toolu_bad', 'title' => 'Bad', 'interpretation' => 'I counted what was asked.', 'sql' => 'SELECT nme FROM store', 'error' => "The query failed: Unknown column 'nme'"],
            ['type' => AssistantManager::EVENT_QUERY, 'toolUseId' => 'toolu_good', 'title' => 'Stores', 'interpretation' => 'I counted the stores of 2025.', 'sql' => 'SELECT COUNT(*) AS n FROM store', 'error' => null],
            ['type' => AssistantManager::EVENT_TEXT, 'text' => 'There they are.'],
        ], $assistantManager->getTimeline($history));

        $this->assertSame([AssistantManager::TOOL_ASK_USER => 1, AssistantManager::TOOL_RUN_QUERY => 2], $assistantManager->getToolCallCounts($history));

        $transcript = $assistantManager->getTranscript($history);
        $this->assertSame(['user', 'tool_call', 'tool_result', 'tool_call', 'tool_result', 'tool_call', 'tool_result', 'assistant'], array_column($transcript, 'role'));
        $this->assertSame(AssistantManager::TOOL_ASK_USER, $transcript[1]['name']);
        $this->assertSame('2025', $transcript[2]['content']);
        $this->assertSame(self::QUERY_SUCCESS_RESULT, $transcript[6]['content']);
        $this->assertStringContainsString('"sql": "SELECT COUNT(*) AS n FROM store"', $transcript[5]['content']);
    }

    public function testRunsAStoredQueryAgain(): void
    {
        $this->queryManager = $this->createMock(QueryManager::class);
        $this->queryManager->expects($this->once())->method('execute')->with('SELECT COUNT(*) AS n FROM store', 100000)
            ->willReturn(['columns' => ['n'], 'rows' => [['n' => 3]], 'truncated' => false]);
        $history = $this->createHistoryWithQuery(['sql' => 'SELECT COUNT(*) AS n FROM store', 'output' => 'text', 'answer_template' => '{value} stores.']);

        $event = $this->createAssistantManager()->runStoredQuery($history, 'toolu_1', 100000);

        $this->assertSame('toolu_1', $event['toolUseId']);
        $this->assertSame('3 stores.', $event['answer']);
        $this->assertSame(AssistantManager::OUTPUT_TEXT, $event['output']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The conversation holds no query "toolu_unknown".');
        $this->createAssistantManager()->runStoredQuery($history, 'toolu_unknown');
    }

    public function testFillsTheAnswerSentenceWithASingleValueOnly(): void
    {
        $cases = [
            'single value' => ['{value} stores.', [['n' => 3]], '3 stores.'],
            'zero' => ['{value} stores.', [['n' => 0]], '0 stores.'],
            'no placeholder' => ['Three stores.', [['n' => 3]], null],
            'no template' => [null, [['n' => 3]], null],
            'several rows' => ['{value} stores.', [['n' => 3], ['n' => 4]], null],
            'several columns' => ['{value} stores.', [['n' => 3, 'm' => 4]], null],
            'no row' => ['{value} stores.', [], null],
            'null value' => ['{value} stores.', [['n' => null]], null],
            'empty value' => ['{value} stores.', [['n' => '']], null],
        ];

        foreach ($cases as $case => [$template, $rows, $expectedAnswer]) {
            $event = $this->runQueryEvent(['answer_template' => $template], $rows);
            $this->assertSame($expectedAnswer, $event['answer'], $case);
        }
    }

    public function testKeepsOnlyAChartDrawnFromColumnsOfTheResult(): void
    {
        $rows = [['year' => 2024, 'stores' => 3, 'employees' => 5]];
        $cases = [
            'bar' => [['type' => 'bar', 'label_column' => 'year', 'value_columns' => ['stores', 'employees', 'stores']], ['type' => 'bar', 'labelColumn' => 'year', 'valueColumns' => ['stores', 'employees']]],
            'pie' => [['type' => 'pie', 'label_column' => 'year', 'value_columns' => ['stores']], ['type' => 'pie', 'labelColumn' => 'year', 'valueColumns' => ['stores']]],
            'unknown type' => [['type' => 'doughnut', 'label_column' => 'year', 'value_columns' => ['stores']], null],
            'unknown label column' => [['type' => 'line', 'label_column' => 'month', 'value_columns' => ['stores']], null],
            'unknown value column' => [['type' => 'line', 'label_column' => 'year', 'value_columns' => ['stores', 'teachers']], null],
            'no value column' => [['type' => 'line', 'label_column' => 'year', 'value_columns' => []], null],
            'no chart' => [null, null],
        ];

        foreach ($cases as $case => [$chart, $expectedChart]) {
            $this->assertSame($expectedChart, $this->runQueryEvent(['chart' => $chart], $rows)['chart'], $case);
        }
    }

    public function testShowsTheFormatTheModelPickedWhenTheResultAllowsIt(): void
    {
        $chart = ['type' => 'bar', 'label_column' => 'year', 'value_columns' => ['n']];
        $cases = [
            'text with an answer' => [['output' => 'text', 'answer_template' => '{value}'], [['n' => 3]], AssistantManager::OUTPUT_TEXT],
            'text without an answer' => [['output' => 'text', 'answer_template' => '{value}'], [['n' => 3], ['n' => 4]], AssistantManager::OUTPUT_TABLE],
            'chart with a chart' => [['output' => 'chart', 'chart' => $chart], [['year' => 2024, 'n' => 3]], AssistantManager::OUTPUT_CHART],
            'chart without a chart' => [['output' => 'chart', 'chart' => null], [['year' => 2024, 'n' => 3]], AssistantManager::OUTPUT_TABLE],
            'excel' => [['output' => 'excel'], [['n' => 3]], AssistantManager::OUTPUT_EXCEL],
            'table' => [['output' => 'table'], [['n' => 3]], AssistantManager::OUTPUT_TABLE],
            'unknown' => [['output' => 'pdf'], [['n' => 3]], AssistantManager::OUTPUT_TABLE],
        ];

        foreach ($cases as $case => [$input, $rows, $expectedOutput]) {
            $this->assertSame($expectedOutput, $this->runQueryEvent($input, $rows)['output'], $case);
        }
    }

    public function testReportsADatabaseFailureAsAQueryError(): void
    {
        $this->queryManager->method('execute')->willThrowException(new ConnectionLost(new class('Server gone away', 'HY000', 2006) extends AbstractException {}, null));

        $event = $this->createAssistantManager()->runStoredQuery($this->createHistoryWithQuery([]), 'toolu_1');

        $this->assertStringContainsString('Server gone away', $event['error']);
        $this->assertNull($event['result']);
    }

    private function runQueryEvent(array $input, array $rows): array
    {
        $this->setUp();
        $this->queryManager->method('execute')->willReturn(['columns' => array_keys($rows[0] ?? []), 'rows' => $rows, 'truncated' => false]);

        return $this->createAssistantManager()->runStoredQuery($this->createHistoryWithQuery($input), 'toolu_1');
    }

    private function createHistoryWithQuery(array $input): array
    {
        return [
            ['role' => 'user', 'content' => 'How many stores?'],
            ['role' => 'assistant', 'provider' => 'scripted', 'message' => ScriptedModelProvider::query('toolu_1', $input['sql'] ?? 'SELECT COUNT(*) AS n FROM store', $input)],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'toolUseID' => 'toolu_1', 'content' => self::QUERY_SUCCESS_RESULT]]],
        ];
    }
}
