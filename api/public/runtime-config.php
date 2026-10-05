<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
\Wellness\Config::loadEnvFile($root . '/.env');
\Wellness\Service\PublicRuntimeConfig::respond();
