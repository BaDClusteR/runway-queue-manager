<?php

use Runway\Service\Provider\PathsProvider;

const QUEUE_MANAGER_ROOT = __DIR__;
const QUEUE_MANAGER_CONFIG_ROOT = QUEUE_MANAGER_ROOT . "/config";

$pathsProvider = PathsProvider::getInstance();
$pathsProvider->addConfigDirectory(QUEUE_MANAGER_CONFIG_ROOT);
$pathsProvider->addEnvFilePath(QUEUE_MANAGER_ROOT . "/.env");
$pathsProvider->addEnvFilePath(QUEUE_MANAGER_ROOT . "/.env.local");
