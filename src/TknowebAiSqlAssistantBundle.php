<?php

namespace Tknoweb\AiSqlAssistantBundle;

use Anthropic\Client;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tknoweb\AiSqlAssistantBundle\Contract\JsonKeyVocabularyInterface;
use Tknoweb\AiSqlAssistantBundle\Contract\ReferentialProviderInterface;
use Tknoweb\AiSqlAssistantBundle\Provider\AnthropicProvider;
use Tknoweb\AiSqlAssistantBundle\Provider\OpenAiCompatibleProvider;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Assistant answering statistics questions in natural language by writing SQL on a MySQL, MariaDB, PostgreSQL, SQL Server or SQLite database, without the AI model ever seeing a stored value.
 * See README.md for its configuration.
 */
class TknowebAiSqlAssistantBundle extends AbstractBundle
{
    public const JSON_KEY_VOCABULARY_TAG = 'tknoweb_ai_sql_assistant.json_key_vocabulary';

    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';
    public const PROVIDER_SERVICE = 'service';

    // Prices of cache writes and reads, as ratios of the input price, when a model does not set them: those of Anthropic, and no cache pricing assumed for the other APIs, which overestimates
    // their cost rather than underestimating it
    private const DEFAULT_CACHE_PRICE_RATIOS = [
        self::PROVIDER_ANTHROPIC => ['write' => 1.25, 'read' => 0.1],
        self::PROVIDER_OPENAI_COMPATIBLE => ['write' => 1.0, 'read' => 1.0],
        self::PROVIDER_SERVICE => ['write' => 1.0, 'read' => 1.0],
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('connection')
                    ->info('DBAL connection the queries of the model run on, logged in as a database user that can read the database and write nothing')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('providers')
                    ->info('Model APIs, by name: "anthropic", "openai_compatible" (OpenAI, Gemini, Mistral, a local model served by Ollama...) or "service" (a ModelProviderInterface service)')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('type')->values([self::PROVIDER_ANTHROPIC, self::PROVIDER_OPENAI_COMPATIBLE, self::PROVIDER_SERVICE])->isRequired()->end()
                            ->scalarNode('api_key')->defaultNull()->end()
                            ->scalarNode('base_url')->info('Base URL of the API, e.g. "https://api.openai.com/v1", required by "openai_compatible"')->defaultNull()->end()
                            ->scalarNode('service')->info('Service id of the provider, required by "service"')->defaultNull()->end()
                            ->scalarNode('effort')->info('"anthropic": effort of the output configuration, null for the default of the API')->defaultValue('medium')->end()
                            ->booleanNode('thinking')->info('"anthropic": adaptive thinking')->defaultTrue()->end()
                            ->booleanNode('server_fallback')->info('"anthropic": answer with a fallback model when the requested one declines a request')->defaultTrue()->end()
                            ->scalarNode('max_tokens_parameter')->info('"openai_compatible": name of the output limit, "max_tokens" for most APIs but OpenAI')->defaultValue('max_completion_tokens')->end()
                            ->booleanNode('strict_tools')->info('"openai_compatible": whether the API supports strict tool schemas')->defaultTrue()->end()
                            ->scalarNode('reasoning_effort')->info('"openai_compatible": reasoning effort of a reasoning model, null to leave it out')->defaultNull()->end()
                            ->floatNode('timeout')->info('"openai_compatible": seconds an answer may take')->defaultValue(120.0)->end()
                        ->end()
                        ->validate()
                            ->ifTrue(fn (array $provider) => self::PROVIDER_OPENAI_COMPATIBLE === $provider['type'] && null === $provider['base_url'])
                            ->thenInvalid('An "openai_compatible" provider needs a "base_url".')
                        ->end()
                        ->validate()
                            ->ifTrue(fn (array $provider) => self::PROVIDER_SERVICE === $provider['type'] && null === $provider['service'])
                            ->thenInvalid('A "service" provider needs a "service".')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('models')
                    ->info('Models a conversation can run on, by key, with their provider and their prices in dollars per million tokens')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('provider')->info('Name of the provider in "providers"')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('id')->info('Model id of the API, e.g. "claude-sonnet-5"')->isRequired()->cannotBeEmpty()->end()
                            ->floatNode('input_price')->isRequired()->end()
                            ->floatNode('output_price')->isRequired()->end()
                            ->floatNode('cache_write_price')->info('Price of the input tokens written to the cache, 1.25 times the input price by default for Anthropic, the input price otherwise')->defaultNull()->end()
                            ->floatNode('cache_read_price')->info('Price of the input tokens read from the cache, 0.1 times the input price by default for Anthropic, the input price otherwise')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_model')->info('Key of the model every new conversation runs on')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('access_attribute')->info('Security attribute the chat is granted on')->defaultValue('ROLE_USER')->end()
                ->scalarNode('route_name_prefix')->info('Name prefix the application imports the routes of the chat with')->defaultValue('tknoweb_ai_sql_assistant_')->end()
                ->scalarNode('csrf_token_id')->info('CSRF token id of the renaming and the archiving of a conversation')->defaultValue('tknoweb_ai_sql_assistant')->end()
                ->scalarNode('base_template')->info('Layout the templates of the chat extend, filling its "body" block')->defaultValue('base.html.twig')->end()
                ->arrayNode('entities')
                    ->info('Entities of the application extending the mapped superclasses of the bundle')
                    ->isRequired()
                    ->children()
                        ->scalarNode('conversation')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('exchange')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('json_value')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('json_path')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('prompt')
                    ->info('Documents of the application added to the base instructions of the bundle in the system prompt')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('instructions')->info('Context and rules of the application: who the users are, their language...')->defaultNull()->end()
                        ->scalarNode('dictionary')->info('Business dictionary: the notions of the domain that can be read in several ways, and their default rule')->defaultNull()->end()
                        ->scalarNode('database')->info('Description of the curated views of the database, if any')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('coded_columns')
                    ->info('Columns of the curated views holding the codes of a backed enum, by column name: their codes are listed in the prompt and labelled on display')
                    ->useAttributeAsKey('column')
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('coded_values_translation_domain')->info('Translation domain of the codes of the coded columns')->defaultValue('messages')->end()
                ->scalarNode('column_label_translation_prefix')->info('Prefix of the translation keys of the headers of the coded columns')->defaultValue('column')->end()
                ->scalarNode('locale')->info('Locale of the labels sent to the model')->defaultValue('%kernel.default_locale%')->end()
                ->arrayNode('forbidden')
                    ->info('What the queries of the model must never read, on top of the conversations and their log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('entities')->scalarPrototype()->end()->end()
                        ->arrayNode('tables')->info('Tables outside the Doctrine mapping')->scalarPrototype()->end()->end()
                        ->arrayNode('fields')->info('Entity fields, by field name, e.g. "password"')->scalarPrototype()->end()->end()
                        ->arrayNode('field_attributes')->info('PHP attributes marking forbidden entity properties')->scalarPrototype()->end()->end()
                    ->end()
                ->end()
                ->arrayNode('unflattened_json_entities')->info('Entities whose JSON columns are not flattened')->scalarPrototype()->end()->end()
                ->scalarNode('referential_provider')->info('Service implementing ReferentialProviderInterface, when the model may search public referentials')->defaultNull()->end()
                ->arrayNode('chart')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('palette')
                            ->scalarPrototype()->end()
                            ->defaultValue(['#3DB7E4', '#FF8849', '#69BE28', '#9B5DE5', '#F15BB5', '#00BBF9', '#FEE440', '#E4572E', '#17BEBB', '#76B041'])
                        ->end()
                        ->variableNode('options')->info('Chart.js options merged into those of every chart')->defaultValue([])->end()
                    ->end()
                ->end()
                ->arrayNode('evaluation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cases_file')->info('YAML file of the test cases of the ai-sql-assistant:evaluate command')->defaultNull()->end()
                        ->scalarNode('output_directory')->defaultValue('%kernel.project_dir%/var/ai_sql_assistant_eval')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $models = [];
        $modelPrices = [];
        foreach ($config['models'] as $key => $model) {
            $provider = $config['providers'][$model['provider']] ?? throw new InvalidConfigurationException(sprintf('The model "%s" of the assistant uses an unknown provider "%s".', $key, $model['provider']));
            $cacheRatios = self::DEFAULT_CACHE_PRICE_RATIOS[$provider['type']];

            $models[$key] = ['provider' => $model['provider'], 'id' => $model['id']];
            $modelPrices[$model['id']] = [
                'input' => $model['input_price'],
                'output' => $model['output_price'],
                'cacheWrite' => $model['cache_write_price'] ?? $model['input_price'] * $cacheRatios['write'],
                'cacheRead' => $model['cache_read_price'] ?? $model['input_price'] * $cacheRatios['read'],
            ];
        }

        if (!isset($models[$config['default_model']])) {
            throw new InvalidConfigurationException(sprintf('The default model "%s" of the assistant is missing from its "models".', $config['default_model']));
        }

        $parameters = $container->parameters();
        $parameters->set('tknoweb_ai_sql_assistant.models', $models);
        $parameters->set('tknoweb_ai_sql_assistant.model_prices', $modelPrices);
        foreach (['default_model', 'access_attribute', 'route_name_prefix', 'csrf_token_id', 'base_template', 'entities', 'prompt', 'coded_columns', 'coded_values_translation_domain', 'column_label_translation_prefix', 'locale', 'unflattened_json_entities'] as $key) {
            $parameters->set('tknoweb_ai_sql_assistant.'.$key, $config[$key]);
        }
        foreach (['entities', 'tables', 'fields', 'field_attributes'] as $key) {
            $parameters->set('tknoweb_ai_sql_assistant.forbidden.'.$key, $config['forbidden'][$key]);
        }
        $parameters->set('tknoweb_ai_sql_assistant.chart.palette', $config['chart']['palette']);
        $parameters->set('tknoweb_ai_sql_assistant.chart.options', $config['chart']['options']);
        $parameters->set('tknoweb_ai_sql_assistant.evaluation.cases_file', $config['evaluation']['cases_file']);
        $parameters->set('tknoweb_ai_sql_assistant.evaluation.output_directory', $config['evaluation']['output_directory']);

        $services = $container->services();
        $services->alias('tknoweb_ai_sql_assistant.query_connection', 'doctrine.dbal.'.$config['connection'].'_connection');
        $this->loadProviders($config['providers'], $container);
        if (null !== $config['referential_provider']) {
            $services->alias(ReferentialProviderInterface::class, $config['referential_provider']);
        }

        $builder->registerForAutoconfiguration(JsonKeyVocabularyInterface::class)->addTag(self::JSON_KEY_VOCABULARY_TAG);
    }

    /**
     * One service per provider, all of them in a locator keyed by provider name, which AssistantManager reads the provider of each model from.
     */
    private function loadProviders(array $providers, ContainerConfigurator $container): void
    {
        $services = $container->services();
        $locator = [];
        foreach ($providers as $name => $provider) {
            $serviceId = 'tknoweb_ai_sql_assistant.provider.'.$name;
            match ($provider['type']) {
                self::PROVIDER_ANTHROPIC => $services->set($serviceId, AnthropicProvider::class)->args([
                    inline_service(Client::class)->arg('$apiKey', $provider['api_key'])->arg('$baseUrl', $provider['base_url']),
                    $provider['effort'],
                    $provider['thinking'],
                    $provider['server_fallback'],
                ]),
                self::PROVIDER_OPENAI_COMPATIBLE => $services->set($serviceId, OpenAiCompatibleProvider::class)->args([
                    service('http_client'),
                    $provider['base_url'],
                    $provider['api_key'],
                    $provider['max_tokens_parameter'],
                    $provider['strict_tools'],
                    $provider['reasoning_effort'],
                    $provider['timeout'],
                ]),
                self::PROVIDER_SERVICE => $services->alias($serviceId, $provider['service']),
            };
            $locator[$name] = service($serviceId);
        }

        $services->set('tknoweb_ai_sql_assistant.provider_locator', ServiceLocator::class)->args([$locator])->tag('container.service_locator');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The mapped superclasses of the bundle, which the entities of the application extend
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TknowebAiSqlAssistantBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => $this->getPath().'/src/Entity',
                        'prefix' => 'Tknoweb\AiSqlAssistantBundle\Entity',
                        'alias' => 'TknowebAiSqlAssistant',
                    ],
                ],
            ],
        ]);

        if (interface_exists(AssetMapperInterface::class)) {
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [$this->getPath().'/assets' => '@tknoweb/ai-sql-assistant-bundle'],
                ],
            ]);
        }
    }
}
