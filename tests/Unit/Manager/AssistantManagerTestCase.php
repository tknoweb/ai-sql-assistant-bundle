<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tknoweb\AiSqlAssistantBundle\Contract\ReferentialProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Manager\AssistantManager;
use Tknoweb\AiSqlAssistantBundle\Manager\JsonCatalogManager;
use Tknoweb\AiSqlAssistantBundle\Manager\PromptManager;
use Tknoweb\AiSqlAssistantBundle\Manager\QueryManager;
use Tknoweb\AiSqlAssistantBundle\Manager\SchemaManager;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\ScriptedModelProvider;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\TestKernel;

/**
 * Builds an assistant on scripted model providers, its query, schema and catalog managers being test doubles.
 */
abstract class AssistantManagerTestCase extends TestCase
{
    protected const MODEL_KEY = 'main';
    // The model the answers of the scripted provider come from by default
    protected const MODEL_ID = TestKernel::MODEL_ID;
    protected const SYSTEM_TEXTS = ['Base instructions', "<database>\nTables\n</database>"];
    protected const PRICES = [
        self::MODEL_ID => ['input' => 2.0, 'output' => 10.0, 'cacheWrite' => 2.5, 'cacheRead' => 0.2],
        'fallback-model' => ['input' => 1.0, 'output' => 5.0, 'cacheWrite' => 1.0, 'cacheRead' => 0.1],
    ];

    protected ScriptedModelProvider $provider;
    // Test stubs, replaced by mocks in the tests that check how they are called
    protected QueryManager $queryManager;
    protected SchemaManager $schemaManager;
    protected JsonCatalogManager $catalogManager;

    protected function setUp(): void
    {
        $this->provider = new ScriptedModelProvider();
        $this->queryManager = $this->createStub(QueryManager::class);
        $this->schemaManager = $this->createStub(SchemaManager::class);
        $this->schemaManager->method('getJsonValueTableName')->willReturn('json_value');
        $this->catalogManager = $this->createStub(JsonCatalogManager::class);
    }

    /**
     * $providers are the providers by name, the scripted one of the test being "scripted"; $models the models by key, the main model of the test running on "scripted".
     */
    protected function createAssistantManager(
        ?ReferentialProviderInterface $referentialProvider = null,
        array $providers = [],
        array $models = [],
        string $defaultModelKey = self::MODEL_KEY,
        array $prices = self::PRICES,
    ): AssistantManager {
        $promptManager = $this->createStub(PromptManager::class);
        $promptManager->method('getSystemTexts')->willReturn(self::SYSTEM_TEXTS);

        return new AssistantManager(
            new ServiceLocator(array_map(fn (ScriptedModelProvider $provider) => fn () => $provider, $providers + ['scripted' => $this->provider])),
            $promptManager,
            $this->queryManager,
            $this->schemaManager,
            $this->catalogManager,
            $models + [self::MODEL_KEY => ['provider' => 'scripted', 'id' => self::MODEL_ID]],
            $prices,
            $defaultModelKey,
            $referentialProvider,
        );
    }

    protected function getEventTypes(array $events): array
    {
        return array_column($events, 'type');
    }
}
