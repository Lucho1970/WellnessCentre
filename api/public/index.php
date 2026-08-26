<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Api.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Auth.php';

use Wellness\Api;
use Wellness\Config;
Config::load(__DIR__ . '/../.env');
Api::handle();
