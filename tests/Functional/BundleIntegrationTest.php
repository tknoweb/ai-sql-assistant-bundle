<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Functional;

use Tknoweb\AiSqlAssistantBundle\Command\EvaluateCommand;
use Tknoweb\AiSqlAssistantBundle\Command\FlattenJsonCommand;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ConversationManager;
use Tknoweb\AiSqlAssistantBundle\Manager\EvaluationManager;
use Tknoweb\AiSqlAssistantBundle\Manager\JsonCatalogManager;
use Tknoweb\AiSqlAssistantBundle\Manager\JsonFlatteningManager;
use Tknoweb\AiSqlAssistantBundle\Manager\ResultManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Conversation;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Document;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\JsonValue;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\FormKeyVocabulary;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * The bundle in a compiled container: its services, the autoconfigured vocabularies, the mapping of its superclasses and its assets.
 */
class BundleIntegrationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testBuildsEveryServiceOfTheBundle(): void
    {
        foreach ([AssistantManager::class, ConversationManager::class, ResultManager::class, EvaluationManager::class, JsonFlatteningManager::class, JsonCatalogManager::class, EvaluateCommand::class, FlattenJsonCommand::class] as $serviceId) {
            $this->assertInstanceOf($serviceId, static::getContainer()->get($serviceId));
        }

        $this->assertSame([TestKernel::MODEL_KEY, TestKernel::OTHER_MODEL_KEY], static::getContainer()->get(AssistantManager::class)->getModelKeys());
    }

    public function testRegistersTheVocabulariesOfTheApplication(): void
    {
        $this->assertInstanceOf(FormKeyVocabulary::class, static::getContainer()->get(JsonCatalogManager::class)->getVocabulary(Document::class, 'content'));
    }

    public function testMapsTheColumnsOfTheFlatTablesWhateverTheNamingStrategy(): void
    {
        $this->assertSame(
            ['id', 'source_table', 'source_column', 'source_id', 'path', 'generic_path', 'path_id1', 'path_id2', 'path_id3', 'value', 'number_value'],
            array_values($this->getEntityManager()->getClassMetadata(JsonValue::class)->getColumnNames())
        );
        // The test application keeps the default naming strategy, which names the other columns in camel case
        $this->assertSame('modelKey', $this->getEntityManager()->getClassMetadata(Conversation::class)->getColumnName('modelKey'));
    }

    public function testExposesItsAssetsToAssetMapper(): void
    {
        $assetMapper = static::getContainer()->get('asset_mapper');

        foreach (['@tknoweb/ai-sql-assistant-bundle/js/controllers/chat_controller.js', '@tknoweb/ai-sql-assistant-bundle/styles/ai-sql-assistant.css'] as $logicalPath) {
            $this->assertNotNull($assetMapper->getAsset($logicalPath), $logicalPath);
        }
    }
}
