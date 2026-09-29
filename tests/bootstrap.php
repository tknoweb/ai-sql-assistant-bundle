<?php

use Symfony\Component\Filesystem\Filesystem;

// The autoloader PHPUnit runs from: the one of the bundle installed on its own, or the one of an application requiring it through a path repository, which does not load the autoload-dev
// namespaces of its dependencies, hence the test namespace registered here in both cases
$loader = require PHPUNIT_COMPOSER_INSTALL;
$loader->addPsr4('Tknoweb\\AiSqlAssistantBundle\\Tests\\', __DIR__);

// The test kernel runs without the debug mode, which would not rebuild its container after a change of the bundle: every run starts from an empty cache, SQLite database included
(new Filesystem())->remove(sys_get_temp_dir().'/tknoweb_ai_sql_assistant_bundle_tests');
