<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ConversationManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Conversation;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\ConversationExchange;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * The storage of the conversations in the entities of the test application, and their log, which never holds a database value.
 */
class ConversationManagerTest extends FunctionalTestCase
{
    private ConversationManager $conversationManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->createDefaultStores();
        $this->conversationManager = static::getContainer()->get(ConversationManager::class);
    }

    public function testCreatesAConversationTitledAfterItsQuestionWithoutSavingIt(): void
    {
        $conversation = $this->conversationManager->createConversation($this->getUser('alice'), TestKernel::MODEL_KEY, "  How many stores?\n");

        $this->assertSame('How many stores?', $conversation->getTitle());
        $this->assertSame(TestKernel::MODEL_KEY, $conversation->getModelKey());
        $this->assertSame('alice', $conversation->getOwner()->getUserIdentifier());
        $this->assertNull($conversation->getId(), 'Only the first turn saves it, so that a failed call leaves nothing behind.');
        $this->assertTrue($this->getEntityManager()->getUnitOfWork()->isScheduledForInsert($conversation));

        $title = $this->conversationManager->createConversation($this->getUser('alice'), TestKernel::MODEL_KEY, str_repeat('é', 300))->getTitle();
        $this->assertSame(255, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);

        $this->expectException(\InvalidArgumentException::class);
        $this->conversationManager->createConversation($this->getUser('alice'), 'unknown', 'Question');
    }

    public function testSavesEachTurnAndLogsItWithoutAnyStoredValue(): void
    {
        $conversation = $this->conversationManager->createConversation($this->getUser('alice'), TestKernel::MODEL_KEY, 'Which stores are open?');
        $this->getProvider()->queue(
            ScriptedModelProvider::question('toolu_q', 'Which ones?', ['Open ones', 'All']),
            ScriptedModelProvider::query('toolu_1', "SELECT name FROM store WHERE status = 'open' ORDER BY name", ['output' => 'table']),
            ScriptedModelProvider::text('Here they are.', usage: ['input' => 1000, 'output' => 100, 'cacheWrite' => 0, 'cacheRead' => 0]),
        );

        $events = $this->conversationManager->continueConversation($conversation, 'Which stores are open?');
        $this->assertSame([AssistantManager::EVENT_QUESTION], array_column($events, 'type'));
        $events = $this->conversationManager->continueConversation($conversation, 'Open ones');
        $this->assertSame([['name' => 'Alpha store'], ['name' => 'Gamma store']], $events[0]['result']['rows']);

        $this->getEntityManager()->clear();
        $conversation = $this->getEntityManager()->find(Conversation::class, $conversation->getId());
        $this->assertCount(6, $conversation->getHistory());
        $this->assertEqualsWithDelta((1000 * 2.0 + 100 * 10.0) / 1000000, $conversation->getCost(), 1e-12);
        $this->assertSame(1100, $conversation->getInputTokens() + $conversation->getOutputTokens());

        $exchanges = $this->getEntityManager()->getRepository(ConversationExchange::class)->findBy([], ['id' => 'ASC']);
        $this->assertCount(2, $exchanges);
        $this->assertSame(['Which stores are open?', false], [$exchanges[0]->getUserInput(), $exchanges[0]->isAnsweredQuestion()]);
        $this->assertSame(['Open ones', true], [$exchanges[1]->getUserInput(), $exchanges[1]->isAnsweredQuestion()]);
        $this->assertSame(TestKernel::MODEL_ID, $exchanges[1]->getModels());

        $loggedQuery = $exchanges[1]->getResponse()[0];
        $this->assertSame("SELECT name FROM store WHERE status = 'open' ORDER BY name", $loggedQuery['sql']);
        $this->assertArrayNotHasKey('result', $loggedQuery);
        $this->assertArrayNotHasKey('answer', $loggedQuery);
        foreach ([$conversation->getHistory(), $exchanges[0]->getResponse(), $exchanges[1]->getResponse()] as $stored) {
            $this->assertStringNotContainsString('Alpha store', json_encode($stored, JSON_THROW_ON_ERROR));
        }
    }

    public function testNeverShowsAConversationToAnotherUserNorOnceArchived(): void
    {
        $conversation = $this->createConversation('alice', 'First question');
        $this->createConversation('alice', 'Second question');
        $this->createConversation('bob', 'Question of Bob');
        $id = $conversation->getId();

        $this->assertSame($id, $this->conversationManager->getOwnConversation($this->getUser('alice'), $id)?->getId());
        $this->assertNull($this->conversationManager->getOwnConversation($this->getUser('bob'), $id));
        $this->assertNull($this->conversationManager->getOwnConversation($this->getUser('alice'), $id + 100));
        $this->assertSame(['Second question', 'First question'], array_map(fn (Conversation $conversation) => $conversation->getTitle(), $this->conversationManager->getConversations($this->getUser('alice'))));

        $this->conversationManager->archiveConversation($conversation);
        $this->getEntityManager()->clear();

        $this->assertNull($this->conversationManager->getOwnConversation($this->getUser('alice'), $id));
        $this->assertSame(['Second question'], array_map(fn (Conversation $conversation) => $conversation->getTitle(), $this->conversationManager->getConversations($this->getUser('alice'))));
        $this->assertNotNull($this->getEntityManager()->find(Conversation::class, $id), 'An archived conversation stays in the database.');
    }

    public function testExportsTheExchangesOfAPeriodUnderAPseudonym(): void
    {
        $this->createConversation('alice', 'How many stores?');
        $oldExchange = $this->getEntityManager()->getRepository(ConversationExchange::class)->findOneBy([])->setCreatedAt(new \DateTimeImmutable('2020-01-15 10:00'));
        $this->getEntityManager()->flush();
        $this->createConversation('bob', 'How many employees?');

        $export = $this->conversationManager->getExchangesExport(new \DateTimeImmutable('today'), new \DateTimeImmutable('today'));

        $this->assertSame(1, $export['exchangeCount']);
        $exchange = $export['exchanges'][0];
        $this->assertSame('How many employees?', $exchange['userInput']);
        $this->assertSame(substr(hash('sha256', 'bob'), 0, 12), $exchange['ownerPseudonym']);
        $this->assertSame([TestKernel::MODEL_KEY, TestKernel::MODEL_ID], [$exchange['conversationModelKey'], $exchange['models']]);
        $this->assertStringNotContainsString('bob', json_encode($export, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Alpha store', json_encode($export, JSON_THROW_ON_ERROR));

        $this->assertSame(1, $this->conversationManager->getExchangesExport(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-01-15'))['exchangeCount']);
        $this->assertNotNull($oldExchange->getId());
    }

    public function testRequiresTheRepositoriesToImplementTheContracts(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->createStub(EntityRepository::class));
        $conversationManager = new ConversationManager(static::getContainer()->get(AssistantManager::class), $entityManager, ['conversation' => Conversation::class, 'exchange' => ConversationExchange::class]);

        try {
            $conversationManager->getConversations($this->getUser('alice'));
            $this->fail('A repository without the contract must be refused.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('ConversationRepositoryInterface', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ConversationExchangeRepositoryInterface');
        $conversationManager->getExchangesExport(new \DateTimeImmutable(), new \DateTimeImmutable());
    }

    /**
     * A conversation of one turn, whose query returns the stores.
     */
    private function createConversation(string $owner, string $question): Conversation
    {
        $conversation = $this->conversationManager->createConversation($this->getUser($owner), TestKernel::MODEL_KEY, $question);
        $this->getProvider()->queue(
            ScriptedModelProvider::query('toolu_1', 'SELECT name FROM store ORDER BY name'),
            ScriptedModelProvider::text('Here they are.'),
        );
        $this->conversationManager->continueConversation($conversation, $question);

        return $conversation;
    }
}
