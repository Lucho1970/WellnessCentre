<?php
declare(strict_types=1);

use Wellness\Api;
use Wellness\Config;
use Wellness\Database;

// Netfirms layout:
//   /wellness-api                 (private application)
//   /public_html/livinlively.ca/api          (public website entry)
//   /public_html/wellness-common-portal/api  (all clinic portals)
//   /public_html/wellness-portal/api         (general landing/admin)
// All entries use the same depth and private application.
$root = dirname(__DIR__, 3) . '/wellness-api';

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'deployment_incomplete', 'message' => 'The API deployment is incomplete.']]);
    exit;
}

require $root . '/vendor/autoload.php';

Config::loadEnvFile($root . '/.env');

$config = Config::fromEnvironment();
$database = new Database($config);
(new Api($config, $database))->handle();
