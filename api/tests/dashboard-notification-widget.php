<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AuditLogger;
use Wellness\Service\DashboardService;

$config = new Config('test', true, 'test', [], 'localhost', 3306, 'none', 'none', '', '', '', '', 3600);
$database = new Database($config);
$service = new DashboardService($database, new AuditLogger($database));
$builtIns = (new ReflectionMethod(DashboardService::class, 'builtIns'))->invoke($service);
$widget = $builtIns['notification_delivery_summary'] ?? null;
if ($widget === null || $widget['renderer'] !== 'notification_summary' || $widget['dataProjection'] !== 'notification_summary'
    || $widget['requiredCapability'] !== 'notifications.view.clinic' || $widget['destination']['page'] !== 'notifications'
    || $widget['workspaces'] !== ['admin']) throw new RuntimeException('Notification widget configuration is invalid.');

$can = new ReflectionMethod(DashboardService::class, 'can');
$actor = static fn(array $roles): AuthContext => new AuthContext(1, 1, '', '', '', 'staff', $roles);
foreach ([['super_admin', true], ['clinic_admin', true], ['reception', false], ['accountant', false], ['practitioner', false]] as [$role, $expected]) {
    if ($can->invoke($service, $actor([$role]), 'notifications.view.clinic') !== $expected) throw new RuntimeException("Wrong notification access for {$role}.");
}

$validate = new ReflectionMethod(DashboardService::class, 'validateDefinition');
if ($validate->invoke($service, $widget)['destination']['page'] !== 'notifications') throw new RuntimeException('Valid widget was rejected.');
foreach ([
    ['requiredCapability' => 'appointments.view.clinic'],
    ['workspaces' => ['practitioner']],
    ['destination' => ['page' => 'appointments']],
    ['parameters' => ['date' => 'today']],
    ['renderer' => 'metric'],
] as $change) {
    try { $validate->invoke($service, array_replace($widget, $change)); throw new RuntimeException('Unsafe notification widget was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}

echo "Dashboard notification widget authorization and validation tests passed.\n";
