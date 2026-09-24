<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/HttpBookingWorker.php';

use Wellness\Tests\Hosted\HttpBookingWorker;

$secret = str_repeat('a', 48);
$body = '{"clinic_id":123,"actor_id":456,"body":{"test":true}}';
$timestamp = (string)time();
$signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
if (!HttpBookingWorker::authorized($secret, $timestamp, $signature, $body)) throw new RuntimeException('Valid worker signature was rejected.');
if (HttpBookingWorker::authorized($secret, $timestamp, $signature, $body . ' ')) throw new RuntimeException('Tampered worker body was accepted.');
if (HttpBookingWorker::authorized($secret, (string)(time() - 120), $signature, $body)) throw new RuntimeException('Expired worker signature was accepted.');
if (HttpBookingWorker::authorized('short', $timestamp, $signature, $body)) throw new RuntimeException('Short worker secret was accepted.');
echo "Signed HTTP booking worker checks passed.\n";
