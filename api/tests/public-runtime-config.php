<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Wellness\Service\PublicRuntimeConfig;

$_ENV['DB_PASSWORD'] = 'must-not-be-exposed';
foreach (['https://livinlively.com', 'https://example.test/clinic/', 'http://localhost:5173/'] as $url) {
    $_ENV['PUBLIC_WEBSITE_URL'] = $url;
    if (PublicRuntimeConfig::fromEnvironment() !== ['publicWebsiteUrl' => rtrim($url, '/') . '/']) {
        throw new RuntimeException('Unexpected public configuration.');
    }
}
foreach (['', 'javascript:alert(1)', 'https://user:pass@example.test/', 'https://example.test/?secret=1', 'https://example.test/#fragment', 'not-a-url'] as $url) {
    $_ENV['PUBLIC_WEBSITE_URL'] = $url;
    try { PublicRuntimeConfig::fromEnvironment(); }
    catch (RuntimeException) { continue; }
    throw new RuntimeException('Invalid base URL was accepted.');
}
echo "Public runtime configuration tests passed.\n";
