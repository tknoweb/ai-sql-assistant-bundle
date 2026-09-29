# tknoweb/ai-sql-assistant-bundle

Symfony bundle (`Tknoweb\AiSqlAssistantBundle`) of a statistics assistant: an AI model writes SQL on a MySQL, MariaDB, PostgreSQL, SQL Server or SQLite database from a question in natural language, the application runs the query and displays its result. Installation and configuration: [README.md](README.md). This file gives the invariants and the conventions to follow when changing the bundle or integrating it.

## Invariants (never to be broken)

- **The model never sees any stored data.** The results of `run_query` go to the screen, never into the history sent to the API; the tool result sent back to the model only says whether the query ran. The only exceptions: the public referentials of `ReferentialProviderInterface`, chosen by the application, and the schema descriptions and the catalog of the JSON paths, which only hold names and labels.
- **The error messages of the database are filtered** (`isQuotableError()` of each dialect, by error code or SQLSTATE): only add a code to it when its message can only quote the query or the schema, never a value.
- **The forbidden tables and columns** (`SchemaManager`) are kept out by the code, not by the grants of the database: any query naming them is refused, and so is the star, or on PostgreSQL a table or its alias written on its own (the whole row). The conversations and their log are always forbidden.
- **A query is checked as its engine reads it**: `SqlDialect::tokenize()` refuses whatever an engine could read in another way (a backslash in a quote, a comment inside a comment, `--` followed by a character on MySQL, a dollar quote on PostgreSQL, beyond ASCII a character that is not a letter). A check of `QueryManager` works on those tokens, the forbidden names apart, looked for in the raw SQL, strings and comments included.
- **The log holds no value**: `ConversationManager::getLoggedEvents()` removes the rows and the filled answer sentences.
- **The JSON catalog only holds vetted paths**: when the keys come from a form (`JsonKeyVocabularyInterface`), each word must exist in the vocabulary, so that a user cannot slip an instruction to the model into it.
- **The system prompt is identical from one request to the next** (cache): nothing variable in `PromptManager::getSystemTexts()` nor in the tool definitions.
- **A history is sent back as it is**: a provider sends back its own raw messages without changing them (the thinking of Claude and its signature, the thought signature of Gemini depend on it), with a single tool call per answer, each one getting its result in the next message.

## Internal architecture

- `Manager\AssistantManager`: tool loop written by hand, independent of the provider: tools, history, timeline, cost. It only reads a message of the model through `ModelProviderInterface::readMessage()`, by the provider recorded in the history entry (the one of the default model for a history older than that recording).
- `Provider\*`: the providers (`AnthropicProvider` through the official SDK, beta API, thinking, server fallback, cache; `OpenAiCompatibleProvider` through HTTP), the neutral message `ModelMessage`, the tool call `ToolCall` and `ModelProviderException`, the only API exception the controller and the command expect. Each provider is a service `tknoweb_ai_sql_assistant.provider.<name>`, all of them gathered in the locator `tknoweb_ai_sql_assistant.provider_locator`.
- `Manager\PromptManager`: base instructions (`resources/prompt/instructions.md`, generic, in English), then the documents of the application (`prompt`), the codes of the coded columns, the list of the tables.
- `Manager\QueryManager`: validation and execution of the queries of the model on the read-only connection, in a transaction always rolled back, filtering of the errors.
- `Dialect\*`: what differs from one engine to another, found from the DBAL platform (`SqlDialect::fromPlatform()`): name of the SQL given to the model, tokenizer and specific checks, session settings (read only, time limit), quotable errors, largest number of parameters, copy and swap of the flat tables. `MySqlDialect` (`MariaDbDialect` extends it), `PostgreSqlDialect`, `SqlServerDialect`, `SqliteDialect`.
- `Manager\SchemaManager`: readable tables and columns from the Doctrine mapping, forbidden names, JSON columns to flatten.
- `Manager\JsonFlatteningManager` and `Manager\JsonCatalogManager`: nightly flattening (`_new` copies swapped by the dialect, a single `RENAME TABLE` on MySQL and MariaDB, rows copied into the live tables in one transaction elsewhere, reading by batches, bounded memory) and catalog of the generic paths.
- `Manager\ConversationManager`: storage of the conversations and of the log in the entities of the application (mapped superclasses `Entity\Abstract*`, contracts `Contract\*`).
- `Manager\ResultManager`: labels, Chart.js chart, Excel file (protected against formula injection).
- `Manager\EvaluationManager` and `Command\EvaluateCommand`: paid test set, programmatic grading.
- `Controller\ChatController`: pages of the chat, relative routes imported by the application with a `name_prefix` equal to `route_name_prefix`.

## Conventions

- Code, comments, README and this file in English. No PHPDoc tags (`@param`, `@return`...).
- Coding standard `@Symfony` of php-cs-fixer (`.php-cs-fixer.dist.php`): run `vendor/bin/php-cs-fixer fix` in the environment of the bundle after a change.
- Nothing specific to an application in the bundle: everything goes through the configuration (`TknowebAiSqlAssistantBundle::configure()`) or through a contract.
- Nothing specific to a database engine in the managers: the SQL that differs from one engine to another goes into its dialect, the rest through DBAL (`modifyLimitQuery()`, `escapeStringForLike()` with an explicit `ESCAPE`, `SqlDialect::quoteName()`, a `CASE WHEN` rather than a boolean summed), never a `LIMIT` or a backtick written by hand. `Connection::quoteIdentifier()` is deprecated in DBAL 4.5.
- The displayed texts go through the translation domain `TknowebAiSqlAssistant` (files of `translations/`, French and English).
- The templates stay in plain Bootstrap 5, without icons nor classes of a theme: the application overrides them.
- Nothing of an application using the bundle is used to develop it: neither its environment, nor its database, nor its choices (its database engine, its AI provider). The bundle has its own Docker environment (`compose.yaml`).
- After a change, run the test suite of the bundle again in that environment, on SQLite then on MySQL (commands in "Tests" of the README, offline, simulated providers); a fix or a feature comes with its test, in `tests/Unit` for the logic of a class, in `tests/Functional` when the test application is needed. PostgreSQL and SQL Server have no server in that environment: a change of their dialect comes with its unit test, on their DBAL platform. Never run `ai-sql-assistant:evaluate` without approval, each case costing a paid call.
- A test must never call a real API: `ScriptedModelProvider` for the loop and the pages, `MockHttpClient` (and `Psr18Client` as the transport of the Anthropic SDK) for the providers.

## Known pitfalls

- A mapped superclass cannot declare indexes: the entities of the application carry them (see README).
- The schema filter of the application must hide the `<table>_new` and `_old` copies, otherwise a `doctrine:migrations:diff` run during a flattening would propose to drop them.
- In development, `--no-debug` uses another compiled container: clear it with `cache:clear --no-debug` after a change of the bundle.
- The raw SQL of the bundle (flattening, catalog) names columns: they must have an explicit name in the mapped superclasses, since the application may use any naming strategy. The test application keeps the default strategy to check it.
- The test kernel runs without debug, hence without rebuilding its container: `tests/bootstrap.php` clears its cache (and the SQLite database) at each run.
