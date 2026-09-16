<?php
declare(strict_types=1);

// Temporary, dependency-free hosting diagnostic. Remove after testing.
// Never reads application configuration, credentials, tokens or database data.
header('X-Wellness-Preflight-Probe: standalone-v1');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === 'https://portal.copihue.ca') {
    header('Access-Control-Allow-Origin: https://portal.copihue.ca');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if (($_GET['status'] ?? '') === '204') {
    http_response_code(204);
    header_remove('Content-Type');
    header_remove('Content-Length');
    exit;
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "Standalone PHP probe reached.\n";
