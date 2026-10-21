<?php
declare(strict_types=1);
require __DIR__ . '/catalogue-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$resource = $_GET['resource'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo json_encode(['error' => 'Only public catalogue reads are supported.']); exit;
}
if (!is_string($resource) || !in_array($resource, ['services', 'practitioners', 'contact'], true) || array_diff(array_keys($_GET), ['resource'])) {
    http_response_code(404); echo json_encode(['error' => 'Public catalogue resource not found.']); exit;
}
try {
    // Fixed clinic host and endpoints; incoming credentials, cookies and query parameters are never forwarded.
    $endpoint = $resource === 'contact' ? 'site-config' : 'public/' . $resource;
    $curl = curl_init('https://wellnexa.copihue.ca/api/v1/' . $endpoint);
    if ($curl === false) throw new RuntimeException('Cannot initialize public catalogue request.');
    $body = '';
    try {
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'WellnexaPublicCatalogue/1.0 (+https://wellnexa.ca)',
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) return 0;
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        if (curl_exec($curl) === false || (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) throw new RuntimeException('Public catalogue upstream unavailable.');
    } finally { curl_close($curl); }
    $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) throw new RuntimeException('Invalid public catalogue response.');
    echo json_encode(['data' => $resource === 'contact' ? wellnexaContactProjection($decoded['data']) : wellnexaCatalogueProjection($resource, $decoded['data'])], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['error' => 'Public catalogue is temporarily unavailable.']);
}
