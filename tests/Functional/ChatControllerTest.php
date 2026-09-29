<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Tknoweb\AiSqlAssistantBundle\Manager\ConversationManager;
use Tknoweb\AiSqlAssistantBundle\Provider\ModelProviderException;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Conversation;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * The pages of the chat in the test application, the routes of the bundle being imported under /assistant with the "assistant_" name prefix.
 */
class ChatControllerTest extends FunctionalTestCase
{
    private const CHART_QUERY = 'SELECT status AS store_status, COUNT(*) AS stores FROM store GROUP BY status ORDER BY status';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The scripted provider must keep its queue from one request to the next
        $this->client->disableReboot();
        $this->resetDatabase();
        $this->createDefaultStores();
    }

    public function testRequiresAnAllowedUser(): void
    {
        $this->client->request('GET', '/assistant/');
        $this->assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->getUser('carol'));
        $this->client->request('GET', '/assistant/');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testStartsAConversationFromItsFirstQuestion(): void
    {
        $this->client->loginUser($this->getUser('alice'));
        $crawler = $this->client->request('GET', '/assistant/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'My conversations');
        $this->assertSelectorTextContains('body', 'No conversation yet');
        $this->assertSelectorTextContains('body', 'the data of the database is never sent to it');

        $this->getProvider()->queue(ScriptedModelProvider::text('There are three stores.'));
        $this->client->submit($crawler->selectButton('Ask')->form(['new_conversation[question]' => 'How many stores?']));

        $conversation = $this->getEntityManager()->getRepository(Conversation::class)->findOneBy([]);
        $this->assertResponseRedirects('/assistant/'.$conversation->getId());
        $this->assertSame(TestKernel::MODEL_KEY, $conversation->getModelKey(), 'A new conversation runs on the default model.');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'How many stores?');
        $this->assertSelectorTextContains('.col-lg-9 .card-body', 'There are three stores.');
        $this->assertSelectorTextContains('.list-group-item.active', 'How many stores?');
        $this->assertSelectorTextContains('.card-footer', 'Questions for this request: 0/10');
    }

    public function testRefusesAnEmptyQuestion(): void
    {
        $this->client->loginUser($this->getUser('alice'));
        $crawler = $this->client->request('GET', '/assistant/');

        $this->client->submit($crawler->selectButton('Ask')->form(['new_conversation[question]' => '  ']));

        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $this->getProvider()->calls);
    }

    public function testKeepsNothingOfAFirstTurnTheApiFailed(): void
    {
        $this->client->loginUser($this->getUser('alice'));
        $crawler = $this->client->request('GET', '/assistant/');

        $this->getProvider()->queue(new ModelProviderException('The model API failed: overloaded'));
        $this->client->submit($crawler->selectButton('Ask')->form(['new_conversation[question]' => 'How many stores?']));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.alert-danger', 'The assistant could not answer.');
        $this->getEntityManager()->clear();
        $this->assertSame([], $this->getEntityManager()->getRepository(Conversation::class)->findAll());
    }

    public function testAnswersThePendingQuestionOfTheAssistant(): void
    {
        $this->client->loginUser($this->getUser('alice'));
        $crawler = $this->client->request('GET', '/assistant/');
        $this->getProvider()->queue(ScriptedModelProvider::question('toolu_q', 'Which stores?', ['Open ones', 'All of them']));
        $this->client->submit($crawler->selectButton('Ask')->form(['new_conversation[question]' => 'How many stores?']));
        $crawler = $this->client->followRedirect();

        $this->assertSelectorTextContains('.col-lg-9 .fw-semibold', 'Which stores?');
        $this->assertSame(['Open ones', 'All of them'], $crawler->filter('[data-ai-sql-assistant-option-param]')->each(fn ($button) => $button->attr('data-ai-sql-assistant-option-param')));
        $this->assertSame('Pick an answer above or type your own…', $crawler->filter('textarea[name="message[message]"]')->attr('placeholder'));
        $this->assertSelectorTextContains('.card-footer', 'Questions for this request: 1/10');

        $this->getProvider()->queue(ScriptedModelProvider::text('There are two open stores.'));
        $this->client->submit($crawler->selectButton('Send')->form(['message[message]' => 'Open ones']));
        $this->assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $this->assertCount(0, $crawler->filter('[data-ai-sql-assistant-option-param]'), 'An answered question offers its options no more.');
        $this->assertSelectorTextContains('.col-lg-9 .card-body', 'There are two open stores.');
        $this->assertSame('toolu_q', $this->getProvider()->calls[1]['history'][2]['content'][0]['toolUseID']);
    }

    public function testShowsOnlyTheOwnConversationsOfTheUser(): void
    {
        $conversationOfBob = $this->createConversation('bob', 'Question of Bob', [ScriptedModelProvider::text('Answer')]);
        $archivedConversation = $this->createConversation('alice', 'Archived question', [ScriptedModelProvider::text('Answer')]);
        static::getContainer()->get(ConversationManager::class)->archiveConversation($archivedConversation);

        $this->client->loginUser($this->getUser('alice'));
        foreach ([$conversationOfBob->getId(), $archivedConversation->getId(), 999] as $id) {
            foreach (['/assistant/%d', '/assistant/%d/query/toolu_1', '/assistant/%d/query/toolu_1/excel'] as $url) {
                $this->client->request('GET', sprintf($url, $id));
                $this->assertResponseStatusCodeSame(404, sprintf($url, $id));
            }
        }

        $this->client->request('GET', '/assistant/');
        $this->assertSelectorTextNotContains('body', 'Question of Bob');
        $this->assertSelectorTextNotContains('body', 'Archived question');
    }

    public function testShowsAQueryResultInEveryFormatItAllows(): void
    {
        $conversation = $this->createConversation('alice', 'Stores by status', [
            ScriptedModelProvider::query('toolu_1', self::CHART_QUERY, [
                'title' => 'Stores by status',
                'interpretation' => 'I counted the stores of each status.',
                'output' => 'chart',
                'chart' => ['type' => 'bar', 'label_column' => 'store_status', 'value_columns' => ['stores']],
            ]),
            ScriptedModelProvider::text('Here they are.'),
        ]);
        $this->client->loginUser($this->getUser('alice'));

        $crawler = $this->client->request('GET', '/assistant/'.$conversation->getId());
        $this->assertSelectorTextContains('h3', 'Stores by status');
        $this->assertSelectorTextContains('h3 + p', 'I counted the stores of each status.');
        $this->assertSame(self::CHART_QUERY, $crawler->filter('pre')->text());
        $frameUrl = sprintf('/assistant/%d/query/toolu_1', $conversation->getId());
        $this->assertSame($frameUrl, $crawler->filter('turbo-frame')->attr('src'));

        // The result is only ever computed when its frame loads
        $crawler = $this->client->request('GET', $frameUrl);
        $this->assertResponseIsSuccessful();
        $this->assertSame('ai-sql-assistant-query-toolu_1', $crawler->filter('turbo-frame')->attr('id'));
        $this->assertSame(['Table', 'Chart'], $crawler->filter('.nav-link')->each(fn ($tab) => trim($tab->text())));
        $this->assertSelectorTextContains('.nav-link.active', 'Chart');
        $this->assertSame(['Store status', 'Stores'], $crawler->filter('thead th')->each(fn ($header) => $header->text()));
        $this->assertSame([['Closed', '1'], ['Open', '2']], $crawler->filter('tbody tr')->each(fn ($row) => $row->filter('td')->each(fn ($cell) => $cell->text())));
        $this->assertCount(1, $crawler->filter('canvas'));
        $this->assertSame($frameUrl.'/excel', $crawler->filter('a[download]')->attr('href'));
    }

    public function testShowsASingleValueAsASentence(): void
    {
        $conversation = $this->createConversation('alice', 'How many stores?', [
            ScriptedModelProvider::query('toolu_1', 'SELECT COUNT(*) AS stores FROM store', ['output' => 'text', 'answer_template' => 'There are {value} stores.']),
            ScriptedModelProvider::text('Anything else?'),
        ]);
        $this->client->loginUser($this->getUser('alice'));

        $crawler = $this->client->request('GET', sprintf('/assistant/%d/query/toolu_1', $conversation->getId()));

        $this->assertSame(['Text', 'Table'], $crawler->filter('.nav-link')->each(fn ($tab) => trim($tab->text())));
        $this->assertSelectorTextContains('.nav-link.active', 'Text');
        $this->assertSelectorTextContains('.tab-pane.active', 'There are 3 stores.');
    }

    public function testExportsAQueryResultToExcel(): void
    {
        $conversation = $this->createConversation('alice', 'Stores by status', [ScriptedModelProvider::query('toolu_1', self::CHART_QUERY, ['title' => 'Stores by status']), ScriptedModelProvider::text('Done')]);
        $this->client->loginUser($this->getUser('alice'));

        $this->client->request('GET', sprintf('/assistant/%d/query/toolu_1/excel', $conversation->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertResponseHeaderSame('Content-Disposition', sprintf('attachment; filename=stores-by-status-%s.xlsx', date('Y-m-d')));
    }

    public function testReportsAQueryThatCannotRunAgain(): void
    {
        $conversation = $this->createConversation('alice', 'Secrets', [ScriptedModelProvider::query('toolu_1', 'SELECT id FROM secret'), ScriptedModelProvider::text('I cannot.')]);
        $this->client->loginUser($this->getUser('alice'));

        // A failed query is left out of the conversation, the assistant explaining the problem itself
        $crawler = $this->client->request('GET', '/assistant/'.$conversation->getId());
        $this->assertCount(0, $crawler->filter('turbo-frame'));
        $this->assertSelectorTextContains('.col-lg-9 .card-body', 'I cannot.');

        $this->client->request('GET', sprintf('/assistant/%d/query/toolu_1', $conversation->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-warning', 'This query could not run again.');

        $this->client->request('GET', sprintf('/assistant/%d/query/toolu_1/excel', $conversation->getId()));
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/assistant/%d/query/toolu_unknown', $conversation->getId()));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testArchivesAConversationWithAValidToken(): void
    {
        $conversation = $this->createConversation('alice', 'To archive', [ScriptedModelProvider::text('Answer')]);
        $id = $conversation->getId();
        $this->client->loginUser($this->getUser('alice'));

        $this->client->request('GET', sprintf('/assistant/%d/archive', $id));
        $this->assertResponseStatusCodeSame(405);
        $this->client->request('POST', sprintf('/assistant/%d/archive', $id), ['_token' => 'forged']);
        $this->assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/assistant/'.$id);
        $this->client->submit($crawler->selectButton('Archive')->form());
        $this->assertResponseRedirects('/assistant/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'The conversation was archived.');
        $this->assertSelectorTextNotContains('body', 'To archive');

        $this->getEntityManager()->clear();
        $this->assertTrue($this->getEntityManager()->find(Conversation::class, $id)->isArchived());
    }

    public function testArchivesFromTheHistoryWithoutLeavingTheConversationBeingRead(): void
    {
        $first = $this->createConversation('alice', 'First question', [ScriptedModelProvider::text('Answer')]);
        $second = $this->createConversation('alice', 'Second question', [ScriptedModelProvider::text('Answer')]);
        $this->client->loginUser($this->getUser('alice'));

        $crawler = $this->client->request('GET', '/assistant/'.$second->getId());
        $this->client->submit($crawler->filter(sprintf('.list-group form[action="/assistant/%d/archive"]', $first->getId()))->form());
        $this->assertResponseRedirects('/assistant/'.$second->getId());
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'The conversation was archived.');
        $this->assertSelectorTextNotContains('.list-group', 'First question');

        // The conversation being read is archived as well, which leaves the home page only
        $this->client->submit($crawler->filter(sprintf('.list-group form[action="/assistant/%d/archive"]', $second->getId()))->form());
        $this->assertResponseRedirects('/assistant/');
    }

    public function testRenamesAConversationFromTheHistory(): void
    {
        $first = $this->createConversation('alice', 'First question', [ScriptedModelProvider::text('Answer')]);
        $second = $this->createConversation('alice', 'Second question', [ScriptedModelProvider::text('Answer')]);
        $conversationOfBob = $this->createConversation('bob', 'Question of Bob', [ScriptedModelProvider::text('Answer')]);
        $this->client->loginUser($this->getUser('alice'));

        $this->client->request('GET', sprintf('/assistant/%d/rename', $first->getId()));
        $this->assertResponseStatusCodeSame(405);
        $this->client->request('POST', sprintf('/assistant/%d/rename', $first->getId()), ['_token' => 'forged', 'title' => 'Forged']);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('POST', sprintf('/assistant/%d/rename', $conversationOfBob->getId()), ['title' => 'Stolen']);
        $this->assertResponseStatusCodeSame(404);

        // Renamed while reading the second conversation, which stays on screen
        $crawler = $this->client->request('GET', '/assistant/'.$second->getId());
        $renameForm = $crawler->filter(sprintf('form[action="/assistant/%d/rename"]', $first->getId()))->form();
        $this->assertSame('First question', $renameForm['title']->getValue());
        $this->client->submit($renameForm, ['title' => ' ']);
        $this->assertResponseStatusCodeSame(400);
        $this->client->submit($renameForm, ['title' => '  Stores by status ']);
        $this->assertResponseRedirects('/assistant/'.$second->getId());
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Second question');
        $this->assertSelectorTextContains('.list-group', 'Stores by status');
        $this->assertSelectorTextNotContains('.list-group', 'First question');

        // The conversation being read, renamed, shows its new title at once
        $this->client->submit($crawler->filter(sprintf('form[action="/assistant/%d/rename"]', $second->getId()))->form(['title' => 'Open stores']));
        $this->assertResponseRedirects('/assistant/'.$second->getId());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Open stores');

        $this->getEntityManager()->clear();
        $this->assertSame('Stores by status', $this->getEntityManager()->find(Conversation::class, $first->getId())->getTitle());
        $this->assertSame('Question of Bob', $this->getEntityManager()->find(Conversation::class, $conversationOfBob->getId())->getTitle());
    }

    /**
     * A conversation of $owner of one turn, the model answering with $answers.
     */
    private function createConversation(string $owner, string $question, array $answers): Conversation
    {
        $conversationManager = static::getContainer()->get(ConversationManager::class);
        $conversation = $conversationManager->createConversation($this->getUser($owner), TestKernel::MODEL_KEY, $question);
        $this->getProvider()->queue(...$answers);
        $conversationManager->continueConversation($conversation, $question);

        return $conversation;
    }
}
