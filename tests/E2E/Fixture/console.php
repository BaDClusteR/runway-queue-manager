<?php

/**
 * The console script of a project (see tests/Support/Project): the "console" file of the project folder defines
 * PROJECT_ROOT and includes it. The project has the library config, the fixture handlers and commands, and its .env.
 */

declare(strict_types=1);

use Runway\Console\IApplication;
use Runway\Service\Provider\PathsProvider;
use Runway\Singleton\Container;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$pathsProvider = PathsProvider::getInstance();
$pathsProvider->addConfigDirectory(__DIR__ . '/config');
$pathsProvider->addEnvFilePath(PROJECT_ROOT . '/.env');

exit(Container::getInstance()->getService(IApplication::class)->run());
