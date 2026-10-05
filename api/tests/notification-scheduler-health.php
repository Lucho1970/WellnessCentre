<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\NotificationSchedulerHealth;

$config = new Config('test', true, 'test', [], 'localhost', 3306, 'none', 'none', '', '', '', '', 3600);
$health = new NotificationSchedulerHealth(new Database($config));
$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(7, 1, '', '', '', $type, $roles);
foreach ([$actor('staff', ['practitioner']), $actor('staff', ['reception']), $actor('staff', ['accountant']), $actor('client', ['clinic_admin'])] as $denied) {
    try { $health->summary($denied); throw new RuntimeException('Unauthorized scheduler health access.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}

$now = new DateTimeImmutable('2026-09-28 16:00:00', new DateTimeZone('UTC'));
$assess = static fn(?array $record, int $overdue): string => NotificationSchedulerHealth::assess($record, $overdue, $now)['state'];
$record = static fn(string $status, ?string $started, ?string $success): array => [
    'status' => $status, 'last_started_at' => $started, 'last_completed_at' => $started,
    'last_success_at' => $success, 'last_failure_at' => $status === 'failed' ? $started : null,
];
if ($assess(null, 0) !== 'not_observed' || $assess(null, 2) !== 'overdue') throw new RuntimeException('Missing scheduler state is misclassified.');
if ($assess($record('succeeded', '2026-09-28 15:45:00', '2026-09-28 15:45:00'), 0) !== 'healthy') throw new RuntimeException('Healthy scheduler is misclassified.');
if ($assess($record('succeeded', '2026-09-28 15:45:00', '2026-09-28 15:45:00'), 3) !== 'overdue') throw new RuntimeException('Overdue queue was hidden by a successful run.');
if ($assess($record('failed', '2026-09-28 15:59:00', '2026-09-28 15:45:00'), 0) !== 'failed') throw new RuntimeException('Failed run is misclassified.');
if ($assess($record('running', '2026-09-28 15:59:00', '2026-09-28 15:45:00'), 0) !== 'running') throw new RuntimeException('Active run is misclassified.');
if ($assess($record('running', '2026-09-28 15:50:00', '2026-09-28 15:45:00'), 0) !== 'stuck') throw new RuntimeException('Stuck run is misclassified.');
if ($assess($record('succeeded', '2026-09-28 15:00:00', '2026-09-28 15:00:00'), 0) !== 'stale') throw new RuntimeException('Stale scheduler is misclassified.');
echo "Notification scheduler health authorization and state tests passed.\n";
