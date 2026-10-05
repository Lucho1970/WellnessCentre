<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\ReminderScheduleService;

$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(1, 1, '', '', '', $type, $roles);
foreach ([$actor('client', ['clinic_admin']), $actor('staff', ['reception']), $actor('staff', ['practitioner'])] as $denied) {
    try { ReminderScheduleService::authorize($denied); throw new RuntimeException('Unauthorized reminder editor was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}
ReminderScheduleService::authorize($actor('staff', ['clinic_admin']));
ReminderScheduleService::authorize($actor('staff', ['super_admin']));
foreach ([60, 180, 1440, 2880, 4320] as $minutes) {
    if (ReminderScheduleService::minutes(['minutes_before' => $minutes]) !== $minutes) throw new RuntimeException('Valid reminder time was rejected.');
}
foreach ([0, 15, 10080, 'bad', null] as $minutes) {
    try { ReminderScheduleService::minutes(['minutes_before' => $minutes]); throw new RuntimeException('Invalid reminder time was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
echo "Reminder schedule authorization and validation tests passed.\n";
