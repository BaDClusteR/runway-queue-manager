<?php

declare(strict_types=1);

// The logs and the lock files of the tests go to a temporary folder.
define('PROJECT_ROOT', sys_get_temp_dir() . '/runway-queue-manager-tests');

require_once dirname(__DIR__) . '/vendor/autoload.php';
