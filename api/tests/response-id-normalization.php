<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Http\Response;

$actual = Response::normalizeNumericIds([
    'data' => [
        'id' => '12',
        'service_id' => '34',
        'available_room_ids' => ['56', '78'],
        'provider_subject' => '123456',
        'object_id' => '123456',
        'tenant_id' => '789012',
        'idempotency_key' => '987654',
    ],
]);

assert($actual['data']['id'] === 12);
assert($actual['data']['service_id'] === 34);
assert($actual['data']['available_room_ids'] === [56, 78]);
assert($actual['data']['provider_subject'] === '123456');
assert($actual['data']['object_id'] === '123456');
assert($actual['data']['tenant_id'] === '789012');
assert($actual['data']['idempotency_key'] === '987654');

echo "response ID normalization: ok\n";
