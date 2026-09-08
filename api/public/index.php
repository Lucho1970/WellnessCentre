<?php
declare(strict_types=1);

use Wellness\Api;
use Wellness\Config;
use Wellness\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
Config::loadEnvFile($root . '/.env');

$config = Config::fromEnvironment();
$database = new Database($config);
(new Api($config, $database))->handle();
