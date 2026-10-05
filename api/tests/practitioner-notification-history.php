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
$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(7, 1, '', '', '', $type, $roles);
foreach ([$actor('staff', ['super_admin']), $actor('staff', ['clinic_admin']), $actor('staff', ['reception']), $actor('client', ['practitioner'])] as $denied) {
    try { $service->practitionerList($denied, []); throw new RuntimeException('Non-practitioner accessed own notification history.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}
$practitioner = $actor('staff', ['practitioner']);
foreach (['status' => 'unknown', 'channel' => 'fax', 'period' => 'week', 'page' => 0] as $key => $value) {
    try { $service->practitionerList($practitioner, [$key => $value]); throw new RuntimeException("Invalid {$key} was accepted."); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
echo "Practitioner notification history authorization and validation tests passed.\n";
