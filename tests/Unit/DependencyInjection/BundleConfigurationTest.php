<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Unit\DependencyInjection;

use Anthropic\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Tknoweb\AiSqlAssistantBundle\Contract\ReferentialProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Provider\AnthropicProvider;
use Tknoweb\AiSqlAssistantBundle\Provider\OpenAiCompatibleProvider;
use Tknoweb\AiSqlAssistantBundle\TknowebAiSqlAssistantBundle;

/**
 * The configuration of the bundle: the services and parameters it builds, and the mistakes it refuses before any request.
 */
class BundleConfigurationTest extends TestCase
{
    private const CONFIG = [
        'connection' => 'assistant',
        'providers' => [
            'claude' => ['type' => 'anthropic', 'api_key' => 'claude-key'],
            'openai' => ['type' => 'openai_compatible', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'openai-key'],
            'local' => ['type' => 'openai_compatible', 'base_url' => 'http://ollama:11434/v1', 'max_tokens_parameter' => 'max_tokens', 'strict_tools' => false, 'reasoning_effort' => 'low', 'timeout' => 300.0],
            'custom' => ['type' => 'service', 'service' => 'app.model_provider'],
        ],
        'models' => [
            'economical' => ['provider' => 'claude', 'id' => 'claude-sonnet-5', 'input_price' => 2.0, 'output_price' => 10.0],
            'gpt' => ['provider' => 'openai', 'id' => 'gpt-5', 'input_price' => 1.25, 'output_price' => 10.0, 'cache_read_price' => 0.125],
            'local' => ['provider' => 'local', 'id' => 'llama', 'input_price' => 0, 'output_price' => 0],
            'custom' => ['provider' => 'custom', 'id' => 'custom-model', 'input_price' => 1.0, 'output_price' => 2.0, 'cache_write_price' => 3.0],
        ],
        'default_model' => 'economical',
        'entities' => ['conversation' => 'App\Entity\Conversation', 'exchange' => 'App\Entity\Exchange', 'json_value' => 'App\Entity\JsonValue', 'json_path' => 'App\Entity\JsonPath'],
    ];

    public function testBuildsTheModelsAndTheirPrices(): void
    {
        $container = $this->load(self::CONFIG);

        $this->assertSame([
            'economical' => ['provider' => 'claude', 'id' => 'claude-sonnet-5'],
            'gpt' => ['provider' => 'openai', 'id' => 'gpt-5'],
            'local' => ['provider' => 'local', 'id' => 'llama'],
            'custom' => ['provider' => 'custom', 'id' => 'custom-model'],
        ], $container->getParameter('tknoweb_ai_sql_assistant.models'));

        // Anthropic cache prices by default, and the full input price for the other APIs unless the model sets them
        $prices = $container->getParameter('tknoweb_ai_sql_assistant.model_prices');
        $this->assertEqualsWithDelta(['input' => 2.0, 'output' => 10.0, 'cacheWrite' => 2.5, 'cacheRead' => 0.2], $prices['claude-sonnet-5'], 1e-9);
        $this->assertEqualsWithDelta(['input' => 1.25, 'output' => 10.0, 'cacheWrite' => 1.25, 'cacheRead' => 0.125], $prices['gpt-5'], 1e-9);
        $this->assertEqualsWithDelta(['input' => 0.0, 'output' => 0.0, 'cacheWrite' => 0.0, 'cacheRead' => 0.0], $prices['llama'], 1e-9);
        $this->assertEqualsWithDelta(['input' => 1.0, 'output' => 2.0, 'cacheWrite' => 3.0, 'cacheRead' => 1.0], $prices['custom-model'], 1e-9);

        $this->assertSame('economical', $container->getParameter('tknoweb_ai_sql_assistant.default_model'));
        $this->assertSame('ROLE_USER', $container->getParameter('tknoweb_ai_sql_assistant.access_attribute'));
        $this->assertSame(['entities' => [], 'tables' => [], 'fields' => [], 'field_attributes' => []], [
            'entities' => $container->getParameter('tknoweb_ai_sql_assistant.forbidden.entities'),
            'tables' => $container->getParameter('tknoweb_ai_sql_assistant.forbidden.tables'),
            'fields' => $container->getParameter('tknoweb_ai_sql_assistant.forbidden.fields'),
            'field_attributes' => $container->getParameter('tknoweb_ai_sql_assistant.forbidden.field_attributes'),
        ]);
    }

    public function testDeclaresOneServicePerProvider(): void
    {
        $container = $this->load(self::CONFIG);

        $claude = $container->getDefinition('tknoweb_ai_sql_assistant.provider.claude');
        $this->assertSame(AnthropicProvider::class, $claude->getClass());
        $client = $claude->getArgument(0);
        $this->assertInstanceOf(Definition::class, $client);
        $this->assertSame(Client::class, $client->getClass());
        $this->assertSame(['$apiKey' => 'claude-key', '$baseUrl' => null], $client->getArguments());
        $this->assertSame(['medium', true, true], array_slice($claude->getArguments(), 1));

        $local = $container->getDefinition('tknoweb_ai_sql_assistant.provider.local');
        $this->assertSame(OpenAiCompatibleProvider::class, $local->getClass());
        $this->assertEquals(new Reference('http_client'), $local->getArgument(0));
        $this->assertSame(['http://ollama:11434/v1', null, 'max_tokens', false, 'low', 300.0], array_slice($local->getArguments(), 1));
        $this->assertSame(['https://api.openai.com/v1', 'openai-key', 'max_completion_tokens', true, null, 120.0], array_slice($container->getDefinition('tknoweb_ai_sql_assistant.provider.openai')->getArguments(), 1));

        $this->assertSame('app.model_provider', (string) $container->getAlias('tknoweb_ai_sql_assistant.provider.custom'));

        $locator = $container->getDefinition('tknoweb_ai_sql_assistant.provider_locator');
        $this->assertSame(ServiceLocator::class, $locator->getClass());
        $this->assertSame(['claude', 'openai', 'local', 'custom'], array_keys($locator->getArgument(0)));
        $this->assertEquals(new Reference('tknoweb_ai_sql_assistant.provider.custom'), $locator->getArgument(0)['custom']);
    }

    public function testAliasesTheQueryConnectionAndTheReferentialProvider(): void
    {
        $container = $this->load(['referential_provider' => 'app.referentials'] + self::CONFIG);

        $this->assertSame('doctrine.dbal.assistant_connection', (string) $container->getAlias('tknoweb_ai_sql_assistant.query_connection'));
        $this->assertSame('app.referentials', (string) $container->getAlias(ReferentialProviderInterface::class));
        $this->assertFalse($this->load(self::CONFIG)->hasAlias(ReferentialProviderInterface::class));
    }

    public function testRefusesAnInvalidConfiguration(): void
    {
        $invalidConfigs = [
            'no base URL' => [['providers' => ['openai' => ['type' => 'openai_compatible']]] + self::CONFIG, 'An "openai_compatible" provider needs a "base_url".'],
            'no service' => [['providers' => ['custom' => ['type' => 'service']]] + self::CONFIG, 'A "service" provider needs a "service".'],
            'unknown type' => [['providers' => ['other' => ['type' => 'mistral']]] + self::CONFIG, 'mistral'],
            'no provider' => [['providers' => []] + self::CONFIG, 'providers'],
            'unknown provider' => [['models' => ['orphan' => ['provider' => 'missing', 'id' => 'x', 'input_price' => 1, 'output_price' => 1]], 'default_model' => 'orphan'] + self::CONFIG, 'The model "orphan" of the assistant uses an unknown provider "missing".'],
            'unknown default model' => [['default_model' => 'missing'] + self::CONFIG, 'The default model "missing" of the assistant is missing from its "models".'],
            'no connection' => [array_diff_key(self::CONFIG, ['connection' => true]), 'connection'],
        ];

        foreach ($invalidConfigs as $case => [$config, $expectedMessage]) {
            try {
                $this->load($config);
                $this->fail(sprintf('The configuration "%s" should have been refused.', $case));
            } catch (InvalidConfigurationException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage(), $case);
            }
        }
    }

    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['kernel.project_dir' => sys_get_temp_dir(), 'kernel.debug' => false, 'kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.default_locale' => 'en'] as $name => $value) {
            $container->setParameter($name, $value);
        }

        (new TknowebAiSqlAssistantBundle())->getContainerExtension()->load([$config], $container);

        return $container;
    }
}
