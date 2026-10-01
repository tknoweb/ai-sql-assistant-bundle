# T'knoweb AI SQL Assistant Bundle

Statistics assistant for Symfony: the user asks a question in natural language, an AI model writes the SQL query, the application runs it and displays the result as a sentence, a table, a chart or an Excel file. The model is your choice: Claude (Anthropic), OpenAI, Gemini, Mistral, a local model served by Ollama, or any other provider plugged in by the application.

**The model never sees any data of the database.** It receives the question, the description of the schema (names of tables and columns, codes) and, when a query fails, the error message of the database, filtered so that it never quotes a value. The result of its queries is displayed by the application: the model does not even know how many rows it holds.

## Claude Code integration in a consuming project

Add these lines to the `CLAUDE.md` of the project:

```md
## SQL assistant
This project uses the tknoweb/ai-sql-assistant-bundle bundle.
@vendor/tknoweb/ai-sql-assistant-bundle/CLAUDE.md
```

The `@vendor/...` line must be plain text, not inside a code block.

---

## How it works

- **Conversation.** The model asks its clarification questions (`ask_user` tool), 10 at most per request, then runs its query (`run_query` tool) with an interpretation sentence stating exactly what is counted. The user checks that sentence, not the SQL, so a query without a title or with an interpretation shorter than 30 characters goes back to the model without being run.
- **Turns.** A message is first saved as the pending turn of its conversation, then run by a request of its own that the page sends, which holds a lock on the turn until its end. The page shows each step of the turn as it comes (thinking, reading the tables, searching a referential, running a query...), and the user may leave it and come back to the answer. A turn stopped before its end, by a failure of the model API or a restart of the web server, shows as failed, its message offered again.
- **Pages.** The history and the main column are two Turbo frames: opening a conversation, starting one, sending a message or the end of a turn only reload the main column, which then reloads the history; renaming or archiving a conversation only reloads the history, or the main column for the conversation being read. The results already shown are kept rather than run again. In the question and message fields, Enter sends and Shift+Enter goes to the line.
- **Sources.** First the curated views the application describes, then every table of the database (the model reads their columns on demand, `describe_tables` tool), then the content of the JSON columns, flattened every night into a dedicated table and read through a view (`search_document_fields` tool to find their paths).
- **Public referentials.** When the application provides some, the model can search them for the exact spelling of a name (`search_public_referential` tool, up to 20 names in a single call, each one going through `ReferentialProviderInterface::search()`). They are the only data it reads: only put there what you accept to send to the AI provider.
- **Formats.** The model picks the first display format from the request; the user then switches between text, table, chart and Excel without any new call.
- **Numbers.** The model rounds what its query computes, to a whole number for a count, an amount or an average of them, to `max_decimals` decimals for a percentage, a rate or a duration. Whatever it wrote, every number of a result keeps `max_decimals` decimals at most (2 by default), without the trailing zeros of a decimal column ("18447981.000000" shows as "18447981").
- **Codes.** A result column shows the labels of its codes, translated by their value in `coded_values_translation_domain`, when the query selects a coded column as it is, whatever its alias: a column the mapping backs with an enum or a discriminator, or one of the `coded_columns` of the views. Inside an expression (`CASE`, `CONCAT`...) it keeps its codes.
- **Cost.** The cost of each conversation is computed from the usage returned by the API, and displayed. The system prompt is cached.
- **History.** Each turn is logged without any value of the database (questions, SQL, costs, duration), to be reviewed and to improve the documents of the prompt. A JSON export by period is provided by `ConversationManager::getExchangesExport()`.

## Requirements

- PHP 8.2+, Symfony 7.1+, Doctrine ORM 3 on DBAL 3.8+ or 4.
- One of these databases, detected from the DBAL connection (see "Database engines"): **MySQL 8**, **MariaDB 10.4+**, **PostgreSQL 10+**, **SQL Server 2017+**, or SQLite 3.25+ for tests and small applications.
- Twig, Symfony forms, Security (a logged-in user), Stimulus and Turbo (Symfony UX), `symfony/ux-chartjs`, PhpSpreadsheet.
- The Lock component, whose store must be shared by every web server of the application (the `flock` store fits a single server), since it tells a running turn from one whose request died.
- An API key of the chosen provider (none for a local model).

## Installation

```bash
composer require tknoweb/ai-sql-assistant-bundle
```

Register the bundle in `config/bundles.php`:

```php
Tknoweb\AiSqlAssistantBundle\TknowebAiSqlAssistantBundle::class => ['all' => true],
```

### 1. A read-only database user

The queries of the model must go through a user that cannot write anything. On MySQL or MariaDB:

```sql
CREATE USER 'app_assistant'@'%' IDENTIFIED BY '<password>';
GRANT SELECT ON app_database.* TO 'app_assistant'@'%';
```

On PostgreSQL, run by the owner of the tables so that the future ones are readable too:

```sql
CREATE ROLE app_assistant LOGIN PASSWORD '<password>';
GRANT CONNECT ON DATABASE app_database TO app_assistant;
GRANT USAGE ON SCHEMA public TO app_assistant;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_assistant;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO app_assistant;
ALTER ROLE app_assistant SET default_transaction_read_only = on;
```

On SQL Server, a user of the single `db_datareader` role, which reads every table, the future ones included:

```sql
CREATE LOGIN app_assistant WITH PASSWORD = '<password>';
USE app_database;
CREATE USER app_assistant FOR LOGIN app_assistant;
ALTER ROLE db_datareader ADD MEMBER app_assistant;
```

and a dedicated DBAL connection:

```yaml
doctrine:
    dbal:
        connections:
            assistant:
                url: '%env(resolve:DATABASE_ASSISTANT_URL)%'
```

The sensitive tables and columns are not kept out by the grants of the database but by the bundle, which refuses any query naming them (see "Security"). A new table therefore becomes readable without any new grant.

### 2. The entities

The bundle provides four mapped superclasses; the application extends them with its own conventions (owner, archiving, table name):

```php
#[ORM\Entity(repositoryClass: AssistantConversationRepository::class)]
class AssistantConversation extends AbstractConversation
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function getOwner(): UserInterface { return $this->user; }
    public function setOwner(UserInterface $owner): static { $this->user = $owner; return $this; }
    public function archive(): void { $this->archivedAt = new \DateTimeImmutable(); }
    public function isArchived(): bool { return null !== $this->archivedAt; }
}

#[ORM\Entity(repositoryClass: AssistantConversationExchangeRepository::class)]
class AssistantConversationExchange extends AbstractConversationExchange
{
    #[ORM\ManyToOne(targetEntity: AssistantConversation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private AssistantConversation $conversation;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // getConversation(), setConversation(ConversationInterface) and getCreatedAt()
}

#[ORM\Entity]
#[ORM\Table(name: 'assistant_json_value')]
#[ORM\Index(columns: ['json_path_id', 'source_id'])]
#[ORM\Index(columns: ['source_id'])]
class AssistantJsonValue extends AbstractJsonValue {}

#[ORM\Entity]
#[ORM\Table(name: 'assistant_json_path')]
#[ORM\Index(columns: ['generic_path'])]
class AssistantJsonPath extends AbstractJsonPath {}
```

The repositories of the first two implement `ConversationRepositoryInterface` (`findForOwner()`, conversations not archived, the most recent first) and `ConversationExchangeRepositoryInterface` (`findBetween()`, for the export). Then generate the migration with `doctrine:migrations:diff`.

A flattened value only holds the id of its path, each path being stored once in the path table, which keeps the table of the values small. Those ids change from one flattening to the next, so the model never reads that table: it queries a view joining each value to its path (`id`, `source_table`, `source_column`, `source_id`, `generic_path`, `path_id1` to `path_id3`, `value`, `number_value`), named by `json_value_view` (`<table of the values>_view` by default). The flattening creates the view, then replaces it on each run: run it once right after the migration. On MySQL and MariaDB, the table of the values can also be compressed, `#[ORM\Table(name: 'assistant_json_value', options: ['row_format' => 'COMPRESSED'])]`, which about halves it, the flattening hardly slower; its copies keep that format.

The flat tables are filled as `<table>_new` copies, then swapped with the live ones (renamed at once on MySQL and MariaDB, the previous tables being kept as `<table>_old` for an instant, copied into the live tables in a single transaction elsewhere): hide those copies from the migrations with a schema filter on the default connection, for instance `schema_filter: ~^(?!assistant_json_(value|path)_(new|old)$)~`.

### 3. The configuration

```yaml
# config/packages/tknoweb_ai_sql_assistant.yaml
tknoweb_ai_sql_assistant:
    connection: assistant                      # DBAL connection of the read-only user
    providers:                                 # model APIs, by name (see "AI providers")
        anthropic: { type: anthropic, api_key: '%env(ANTHROPIC_API_KEY)%' }
        openai: { type: openai_compatible, base_url: 'https://api.openai.com/v1', api_key: '%env(OPENAI_API_KEY)%' }
    models:                                    # prices in dollars per million tokens
        economical: { provider: anthropic, id: 'claude-sonnet-5', input_price: 2.0, output_price: 10.0 }
        precise: { provider: anthropic, id: 'claude-opus-5', input_price: 5.0, output_price: 25.0 }
        gpt: { provider: openai, id: 'gpt-5', input_price: 1.25, output_price: 10.0, cache_read_price: 0.125 }
    default_model: economical                  # model of every new conversation
    access_attribute: ROLE_USER                # security attribute granting access to the chat
    route_name_prefix: assistant_              # the "name_prefix" of the route import
    csrf_token_id: tknoweb_ai_sql_assistant    # CSRF token of the renaming and the archiving
    base_template: base.html.twig              # layout the templates extend, "body" block
    entities:
        conversation: App\Entity\AssistantConversation
        exchange: App\Entity\AssistantConversationExchange
        json_value: App\Entity\AssistantJsonValue
        json_path: App\Entity\AssistantJsonPath
    json_value_view: assistant_json_value_view # view the model reads the flattened values through
    prompt:                                    # documents added to the base instructions of the bundle
        instructions: '%kernel.project_dir%/docs/assistant_instructions.md'   # context, language, examples
        dictionary: '%kernel.project_dir%/docs/assistant_dictionary.md'       # ambiguous notions and their default rules
        database: '%kernel.project_dir%/docs/assistant_views.md'              # description of the curated views
    coded_columns:                             # columns of views holding the codes of an enum (the mapping gives the ones of the tables)
        order_status: App\Enum\OrderStatus
    coded_values_translation_domain: messages  # translation domain of the codes
    column_label_translation_prefix: column    # header key of a coded column: columnOrderStatus
    locale: en                                 # language of the labels sent to the model
    max_decimals: 2                            # decimals a displayed number keeps at most
    forbidden:                                 # never readable, on top of the conversations and their log
        entities: [App\Entity\ApiToken]
        tables: [messenger_messages]
        fields: [password]
        field_attributes: []
    unflattened_json_entities: []              # entities whose JSON columns are not flattened
    referential_provider: App\Assistant\PublicReferentials   # optional
    chart:
        palette: ['#3DB7E4', '#FF8849']
        options: {}                            # Chart.js options merged into every chart
    evaluation:
        cases_file: '%kernel.project_dir%/docs/assistant_eval_cases.yaml'
```

### 4. The routes

```yaml
# config/routes.yaml
ai_sql_assistant:
    resource: '@TknowebAiSqlAssistantBundle/config/routes.php'
    prefix: /assistant
    name_prefix: assistant_       # same as "route_name_prefix"
```

### 5. The assets

With AssetMapper, the `assets` folder of the bundle is declared under `@tknoweb/ai-sql-assistant-bundle`. Declare its three files in `importmap.php`:

```php
'@tknoweb/ai-sql-assistant-bundle/js/controllers/chat_controller.js' => [
    'path' => '@tknoweb/ai-sql-assistant-bundle/js/controllers/chat_controller.js',
],
'@tknoweb/ai-sql-assistant-bundle/js/controllers/history_controller.js' => [
    'path' => '@tknoweb/ai-sql-assistant-bundle/js/controllers/history_controller.js',
],
'@tknoweb/ai-sql-assistant-bundle/styles/ai-sql-assistant.css' => [
    'path' => '@tknoweb/ai-sql-assistant-bundle/styles/ai-sql-assistant.css',
    'type' => 'css',
],
```

then register the Stimulus controllers under the names **`ai-sql-assistant`** (the conversation) and **`ai-sql-assistant-history`** (the renaming of a conversation in the history) and import the stylesheet:

```js
import AiSqlAssistant from '@tknoweb/ai-sql-assistant-bundle/js/controllers/chat_controller.js';
import AiSqlAssistantHistory from '@tknoweb/ai-sql-assistant-bundle/js/controllers/history_controller.js';
import '@tknoweb/ai-sql-assistant-bundle/styles/ai-sql-assistant.css';

app.register('ai-sql-assistant', AiSqlAssistant);
app.register('ai-sql-assistant-history', AiSqlAssistantHistory);
```

### 6. The JSON flattening, every night

```cron
0 3 * * * cd /path/to/project && php bin/console ai-sql-assistant:flatten-json
```

The command reads each JSON column a few rows at a time: its memory stays bounded whatever the volume. In development, run it with `--no-debug`, since the Doctrine profiler otherwise keeps every query in memory.

## AI providers

Each model of `models` names a provider of `providers`. A conversation keeps its model until its end, and each message of the model keeps in the history the name of the provider that wrote it: removing a provider from the configuration makes the conversations that used it unreadable.

- **`anthropic`**: the Claude models, through the official SDK. Adaptive thinking (`thinking`), effort level (`effort`, `medium` by default), server-side fallback on another model when the requested one declines a request (`server_fallback`), prompt cache. By default, the cache writes cost 1.25 times the input price, the cache reads 0.1 times.
- **`openai_compatible`**: any API compatible with the "chat completions" of OpenAI, with its `base_url`:
  - OpenAI: `https://api.openai.com/v1`;
  - Gemini: `https://generativelanguage.googleapis.com/v1beta/openai`, with `max_tokens_parameter: max_tokens`;
  - a local model served by Ollama: `http://<host>:11434/v1`, without any key, with `max_tokens_parameter: max_tokens`, and `strict_tools: false` when the model does not support strict schemas;
  - `reasoning_effort` for a reasoning model, `timeout` in seconds.

  The cache is handled by the API itself there: set the `cache_read_price` of the model when the API bills the cached input at a lower price, the bundle counting the full price otherwise, which overestimates the cost rather than underestimating it.
- **`service`**: a service of the application implementing `Contract\ModelProviderInterface` (`service: App\Assistant\MyProvider`), for any other API. It sends the history to the model, returns a `Provider\ModelMessage` (texts, tool call, stop reason, tokens) and reads the raw messages it stored itself. It must send its messages back to the model as it received them, with one tool call at most per answer, and report a failure of the API with a `Provider\ModelProviderException`.

The provider receives the questions of the users, the schema and the filtered error messages, never any data of the database: its retention terms for the questions still have to be validated before using it.

## Database engines

The bundle reads the engine from the DBAL platform of the `connection`, and adapts to it the SQL dialect named to the model, the reading of its queries (quotes, comments), the settings of their session, the errors passed on to the model and the swap of the flattening. Everything else goes through DBAL. Whatever the engine, each query of the model runs in a transaction that is always rolled back.

- **MySQL 8 and MariaDB**: read-only session and time limit (`max_execution_time`, `max_statement_time` on MariaDB), copies of the flattening swapped by a single `RENAME TABLE`.
- **PostgreSQL**: read-only session and `statement_timeout`. A query may not name a table or its alias on its own (`SELECT t FROM employee t`, `row_to_json(t)`), which PostgreSQL reads as the whole row, every column included, nor call the functions reading the files of the server or running the SQL of a string.
- **SQL Server**: it has no read-only session, so the user must only be a member of `db_datareader`. Since SQL Server chains statements without any semicolon, every statement keyword is refused (`SET`, `EXEC`, `WAITFOR`, `DECLARE`...), as well as `OPENQUERY`, `OPENROWSET` and `OPENDATASOURCE`. The time limit of a query needs the `pdo_sqlsrv` driver, the `sqlsrv` one only bounding the waits for a lock. The flattening binds at most 2100 parameters at once, the limit of SQL Server.
- **SQLite**: connection in `query_only` mode, but no time limit.

A query is also refused when an engine could read it in another way than the bundle, depending on its settings or its version: a backslash in a string or a quoted name, a comment inside a comment, `--` directly followed by a character on MySQL, a dollar-quoted string on PostgreSQL, outside strings a character beyond ASCII that is not a letter.

## Customization

- **Templates.** The Bootstrap 5 templates of the bundle (`templates/chat/`) are overridden in `templates/bundles/TknowebAiSqlAssistantBundle/chat/`. They receive `baseTemplate`, `routePrefix` and `csrfTokenId`. An override of `index.html.twig` or `show.html.twig` keeps the Turbo frames and the targets of the `ai-sql-assistant` controller they hold: the history frame `ai-sql-assistant-history`, the main column frame `ai-sql-assistant-main` (`data-turbo-action="advance"` and its two actions, on `turbo:frame-load` and `turbo:before-frame-render`) and, on a conversation, its `conversation` target with its `data-*` attributes, the turn frame `ai-sql-assistant-turn` and the `end` target at the end of the messages, where the conversation opens. On a wide screen the chat holds in the window, the history and the messages each scrolling on their own: the override keeps the classes `ai-sql-assistant` (the element of the controller), `ai-sql-assistant-columns` (the row of the two columns), `ai-sql-assistant-history` and `ai-sql-assistant-main` (the frames) and `ai-sql-assistant-conversation` (the `conversation` target, its card holding the messages in its `card-body`), and a page that puts something around the chat sets the height it leaves in the CSS variable `--ai-sql-assistant-height` (`100vh` by default). An override of `_history.html.twig` keeps the `data-turbo-frame` of its links and of the forms of the conversation being read, and one of `_event.html.twig` the `data-turbo-permanent` of the result frames.
- **Texts.** Translation domain `TknowebAiSqlAssistant` (French and English provided). A `translations/TknowebAiSqlAssistant+intl-icu.<locale>.yaml` file of the application overrides the keys it needs, for instance `questionPlaceholder`.
- **Public referentials.** A service implementing `ReferentialProviderInterface`: names of the referentials, description for the model, search. Declared in `referential_provider`.
- **Form keys.** When the keys of a JSON column come from a form, a user can post any key to it: a service implementing `JsonKeyVocabularyInterface` (registered automatically) gives the allowed words and the labels, and only the paths made of these words are catalogable, the only ones a field search gives to the model. Without a vocabulary, a key only has to look like a field name. The labels of a path are given by key segment, or by the whole generic path when a form builds its keys in loops from pieces that tell nothing on their own; they are kept per template version, and a field search returns them with the group of paths of their version, the beginning they share given once. The model can restrict a field search to a kind of document or a template version.

## Security

- Database user without any write grant, read-only session where the engine has one, a transaction rolled back after each query, 10 seconds at most per query, a single `SELECT` statement (or `WITH`).
- Refusal of any query naming a forbidden table or column, of the star (`SELECT *`, `t.*`, `COUNT(*)` apart), of the `TABLE` and `INTO` keywords, of the keywords of the statements that write (`UPDATE`, `DELETE`..., which a `WITH` could introduce), of the executable comments `/*! */`, and the checks specific to each engine (see "Database engines").
- Only the error messages that can only quote the query or the schema go back to the model, by error code or SQLSTATE of each engine; the other ones (an `EXTRACTVALUE` error built to leak a value on MySQL, a failed conversion quoting a string on SQL Server, for instance) are reduced to their code.
- Each user only sees their own conversations; the ones of the other users answer 404. The conversations and their log are always forbidden to the queries of the model.

## Tests

The PHPUnit suite of the bundle runs offline: the model APIs are simulated, no test calls a paid API.

The repository provides its own Docker environment (`compose.yaml`): PHP with the extensions and drivers it needs, and a throwaway MySQL database kept in memory. No port is published, so it never gets in the way of another project. PostgreSQL and SQL Server are covered by the unit tests, on their DBAL platforms, without any server.

```bash
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit                  # on SQLite

docker compose up -d --wait mysql                               # then on MySQL
docker compose run --rm -e "AI_SQL_ASSISTANT_TEST_DATABASE_URL=mysql://root:test@mysql:3306/assistant_test?serverVersion=8.4&charset=utf8mb4" php vendor/bin/phpunit
docker compose stop mysql

docker compose run --rm php vendor/bin/php-cs-fixer fix         # coding standard (@Symfony rules)
```

Without Docker, with PHP and its extensions installed: `composer install`, then `vendor/bin/phpunit` and `vendor/bin/php-cs-fixer fix`.

- `tests/Unit`: the logic of each class, its dependencies simulated (dialect of each engine, guard of the queries and filtering of the errors on each engine, conversation loop, Anthropic and OpenAI compatible providers on a simulated HTTP client, JSON flattening and catalog, results and Excel, evaluation, configuration of the bundle).
- `tests/Functional`: the bundle in a test application (`tests/Fixtures/TestKernel.php`: a few entities, three users, a scripted model provider), on a SQLite file: schema described to the model, prompt, queries, catalog, conversations and log, pages of the chat, evaluation command.
- The functional tests run on the engine of `AI_SQL_ASSISTANT_TEST_DATABASE_URL` when it is set, whose database must be named `*_test` since all of its tables are dropped: the swap of the flattening and the read-only session are then those of that engine.

When an application installs the bundle through a Composer repository of type `path`, the suite also runs with the PHPUnit of the application: `vendor/bin/phpunit -c <path of the bundle>/phpunit.xml.dist`.

## Evaluation

`php bin/console ai-sql-assistant:evaluate` replays the cases of the `evaluation.cases_file` file with a simulated user and grades each conversation without ever reading a result. **Each case costs a paid call.** Options: `--dry-run`, `--case`, `--model`, `--reps`, `--variant` (`baseline`, `v1`...). The results follow the format of the report builder of the Claude API skill.

```yaml
- id: orders-2025
  tags: [precise-request]
  question: "How many orders in 2025?"
  expectQuestion: false        # must it ask at least one question?
  expectQuery: true            # must it end on a query that runs?
  sqlMustContain: ['orders', '2025']
  expectedOutput: text         # text, table, chart or excel
  expectReferentialSearch: false
  maxToolCalls: 8              # optional: most tool calls allowed, questions and queries included (0 for a request outside the data)
  answers: []                  # answers of the simulated user, the first option offered otherwise
```
