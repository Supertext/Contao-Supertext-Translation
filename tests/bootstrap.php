<?php

declare(strict_types=1);

// Unit tests use this repository's dependencies. Integration tests run inside a real
// Contao installation (CONTAO_PROJECT_DIR) that has this bundle installed, with that
// installation's autoloader and PHPUnit.
$project = getenv('CONTAO_PROJECT_DIR') ?: \dirname(__DIR__);
$loader = require $project.'/vendor/autoload.php';
$loader->addPsr4('Supertext\\ContaoTranslation\\Tests\\', __DIR__);
