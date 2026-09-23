<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\NotificationStatusService;

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
foreach ([0, -1, 'abc', 10001] as $invalid) {
    try { $service->list($actor('staff', ['clinic_admin']), ['page' => $invalid]); throw new RuntimeException('Invalid page was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
echo "Notification status authorization and validation tests passed.\n";
