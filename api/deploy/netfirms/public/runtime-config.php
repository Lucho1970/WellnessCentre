<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3) . '/wellness-api';
require $root . '/vendor/autoload.php';
\Wellness\Config::loadEnvFile($root . '/.env');
\Wellness\Service\PublicRuntimeConfig::respond();
