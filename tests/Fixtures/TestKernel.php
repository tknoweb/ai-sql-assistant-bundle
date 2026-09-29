<?php

namespace Tknoweb\AiSqlAssistantBundle\Tests\Fixtures;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\UX\Chartjs\ChartjsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\AuditLog;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Conversation;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\ConversationExchange;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\JsonPath;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\JsonValue;
use Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity\Secret;
use Tknoweb\AiSqlAssistantBundle\TknowebAiSqlAssistantBundle;

/**
 * Application the functional tests run the bundle in: a few entities, three users, and the scripted model provider in place of any real API.
 * Its database is a SQLite file, both connections sharing it, unless AI_SQL_ASSISTANT_TEST_DATABASE_URL gives another test database (MySQL, MariaDB, PostgreSQL, SQL Server). The Doctrine
 * naming strategy is left to its default, so that nothing of the bundle depends on the underscore one most applications use.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const DATABASE_URL_VARIABLE = 'AI_SQL_ASSISTANT_TEST_DATABASE_URL';
    public const MODEL_KEY = 'scripted';
    public const MODEL_ID = 'scripted-model';
    public const OTHER_MODEL_KEY = 'other';
    public const OTHER_MODEL_ID = 'other-model';

    public static function getDatabaseUrl(): ?string
    {
        $url = $_SERVER[self::DATABASE_URL_VARIABLE] ?? $_ENV[self::DATABASE_URL_VARIABLE] ?? getenv(self::DATABASE_URL_VARIABLE);

        return is_string($url) && '' !== $url ? $url : null;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new StimulusBundle();
        yield new ChartjsBundle();
        yield new TknowebAiSqlAssistantBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tknoweb_ai_sql_assistant_bundle_tests/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tknoweb_ai_sql_assistant_bundle_tests/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'default_locale' => 'en',
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'translator' => ['default_path' => '%kernel.project_dir%/translations'],
            'validation' => ['enabled' => true],
            'lock' => 'in-memory',
            'asset_mapper' => ['paths' => []],
        ] + (self::VERSION_ID >= 70300 ? ['property_info' => ['with_constructor_extractor' => false]] : []));

        $container->extension('security', [
            'password_hashers' => [InMemoryUser::class => 'plaintext'],
            'providers' => [
                'users' => [
                    'memory' => [
                        'users' => [
                            'alice' => ['password' => 'alice', 'roles' => ['ROLE_ASSISTANT']],
                            'bob' => ['password' => 'bob', 'roles' => ['ROLE_ASSISTANT']],
                            'carol' => ['password' => 'carol', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => ['lazy' => true, 'provider' => 'users', 'http_basic' => []],
            ],
        ]);

        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates',
            'strict_variables' => true,
        ]);

        $databaseUrl = self::getDatabaseUrl();
        $connection = null !== $databaseUrl ? ['url' => $databaseUrl] : ['driver' => 'pdo_sqlite', 'path' => '%kernel.cache_dir%/test.sqlite'];
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => ['default' => $connection, 'assistant' => $connection],
            ],
            'orm' => [
                'report_fields_where_declared' => true,
                'auto_mapping' => false,
                'mappings' => [
                    'Fixtures' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => '%kernel.project_dir%/Entity',
                        'prefix' => 'Tknoweb\AiSqlAssistantBundle\Tests\Fixtures\Entity',
                    ],
                ],
            ] + (\PHP_VERSION_ID >= 80400 ? ['enable_native_lazy_objects' => true] : ['enable_lazy_ghost_objects' => true]),
        ]);

        $container->extension('tknoweb_ai_sql_assistant', [
            'connection' => 'assistant',
            'providers' => [
                'scripted' => ['type' => 'service', 'service' => ScriptedModelProvider::class],
            ],
            'models' => [
                self::MODEL_KEY => ['provider' => 'scripted', 'id' => self::MODEL_ID, 'input_price' => 2.0, 'output_price' => 10.0],
                self::OTHER_MODEL_KEY => ['provider' => 'scripted', 'id' => self::OTHER_MODEL_ID, 'input_price' => 1.0, 'output_price' => 4.0],
            ],
            'default_model' => self::MODEL_KEY,
            'access_attribute' => 'ROLE_ASSISTANT',
            'route_name_prefix' => 'assistant_',
            'entities' => [
                'conversation' => Conversation::class,
                'exchange' => ConversationExchange::class,
                'json_value' => JsonValue::class,
                'json_path' => JsonPath::class,
            ],
            'prompt' => [
                'instructions' => '%kernel.project_dir%/prompt/instructions.md',
                'dictionary' => '%kernel.project_dir%/prompt/dictionary.md',
                'database' => '%kernel.project_dir%/prompt/views.md',
            ],
            'coded_columns' => ['store_status' => StoreStatus::class],
            'locale' => 'fr',
            'forbidden' => [
                'entities' => [Secret::class],
                'tables' => ['messenger_messages'],
                'fields' => ['password'],
                'field_attributes' => [Sensitive::class],
            ],
            'unflattened_json_entities' => [AuditLog::class],
            'referential_provider' => PublicReferentialProvider::class,
            'chart' => ['palette' => ['#111111', '#222222'], 'options' => ['plugins' => ['datalabels' => ['display' => true]]]],
            'evaluation' => [
                'cases_file' => '%kernel.project_dir%/evaluation/cases.yaml',
                'output_directory' => '%kernel.cache_dir%/evaluation',
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // The errors the tests provoke on purpose (a denied access, a failed API call...) would otherwise be written to the output of PHPUnit
        $services->set('logger', NullLogger::class);
        $services->set(ScriptedModelProvider::class)->public();
        $services->set(PublicReferentialProvider::class)->public();
        $services->set(FormKeyVocabulary::class);
        $services->load('Tknoweb\\AiSqlAssistantBundle\\Tests\\Fixtures\\Repository\\', __DIR__.'/Repository');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@TknowebAiSqlAssistantBundle/config/routes.php')
            ->prefix('/assistant')
            ->namePrefix('assistant_');
    }
}
