<?php

declare(strict_types=1);

use Runway\Service\Provider\PathsProvider;

// The logs and the lock files of the tests go to a temporary folder.
define('PROJECT_ROOT', sys_get_temp_dir() . '/runway-queue-manager-tests');

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The fixture job handlers (see tests/Fixture) for the integration tests.
PathsProvider::getInstance()->addConfigDirectory(__DIR__ . '/config');

// The last one wins: the .env.local of the developer does not change the tests.
PathsProvider::getInstance()->addEnvFilePath(__DIR__ . '/config/test.env');
