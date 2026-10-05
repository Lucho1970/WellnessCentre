<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\NotificationStatusService;
use Wellness\Service\NotificationActivityWindow;

$config = new Config('test', true, 'test', [], 'localhost', 3306, 'none', 'none', '', '', '', '', 3600);
$service = new NotificationStatusService(new Database($config));
$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(1, 1, '', '', '', $type, $roles);
foreach ([$actor('staff', ['practitioner']), $actor('staff', ['reception']), $actor('staff', ['accountant']), $actor('client', ['super_admin'])] as $denied) {
    try { $service->list($denied, []); throw new RuntimeException('Unauthorized account was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}
foreach (['unknown', 'sent\' OR 1=1 --'] as $invalid) {
    try { $service->list($actor('staff', ['super_admin']), ['status' => $invalid]); throw new RuntimeException('Invalid status was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
foreach (['fax', "sms' OR 1=1 --"] as $invalid) {
    try { $service->list($actor('staff', ['super_admin']), ['channel' => $invalid]); throw new RuntimeException('Invalid channel was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
foreach (['month', "week' OR 1=1 --"] as $invalid) {
    try { $service->list($actor('staff', ['clinic_admin']), ['period' => $invalid]); throw new RuntimeException('Invalid period was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
foreach ([0, -1, 'abc', 10001] as $invalid) {
    try { $service->list($actor('staff', ['clinic_admin']), ['page' => $invalid]); throw new RuntimeException('Invalid page was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
$now = new DateTimeImmutable('2026-09-27 12:00:00', new DateTimeZone('UTC'));
if (NotificationActivityWindow::bounds('last7', 'America/Toronto', $now) !== ['2026-09-20 12:00:00', '2026-09-27 12:00:01']) throw new RuntimeException('Last 7 days must be a rolling period.');
if (NotificationActivityWindow::bounds('week', 'America/Toronto', $now) !== ['2026-09-21 04:00:00', '2026-09-27 12:00:01']) throw new RuntimeException('Calendar week boundaries must use clinic-local Monday.');
$dstNow = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
if (NotificationActivityWindow::bounds('today', 'America/Toronto', $dstNow) !== ['2026-11-01 04:00:00', '2026-11-01 12:00:01']) throw new RuntimeException('Day boundaries must respect daylight saving time.');
echo "Notification status authorization and validation tests passed.\n";
