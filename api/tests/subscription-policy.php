<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Service\SubscriptionPolicy as P;

$checks = 0;
$check = function (bool $ok, string $why) use (&$checks): void {
    if (!$ok) throw new RuntimeException($why);
    $checks++;
};
foreach ([
    ['2026-08-31 13:15:00', '2027-02-28 14:15:00'],
    ['2027-08-31 13:15:00', '2028-02-29 14:15:00'],
    ['2026-10-26 13:00:00', '2027-04-26 13:00:00'],
] as [$start, $expected]) {
    $check(P::trialEndsAt(new DateTimeImmutable($start, new DateTimeZone('UTC')), 'America/Toronto')->format('Y-m-d H:i:s') === $expected, 'Six calendar months preserve local time and clamp month ends');
}
$now = new DateTimeImmutable('2026-10-26 12:00:00', new DateTimeZone('UTC'));
$subscription = ['status'=>'trialing', 'starts_at'=>'2026-10-26 12:00:00', 'trial_ends_at'=>'2027-04-26 12:00:00'];
$check(P::allowsNewActivity($subscription, $now), 'Trial includes its start');
$check(!P::allowsNewActivity($subscription, $now->modify('-1 second')), 'Trial cannot begin early');
$check(!P::allowsNewActivity($subscription, new DateTimeImmutable('2027-04-26T12:00:00Z')), 'Trial ends without waiting for cron');
foreach (['pending','restricted','canceled','suspended','unknown'] as $state) {
    $check(!P::allowsNewActivity(array_replace($subscription, ['status'=>$state]), $now), 'Noneligible state denies new activity');
}
foreach (['active'=>'paid_through_at', 'past_due'=>'grace_ends_at'] as $state=>$end) {
    $record = array_replace($subscription, ['status'=>$state, $end=>'2026-10-27 12:00:00']);
    $check(P::allowsNewActivity($record, $now), 'Paid or grace interval allows activity');
    $check(!P::allowsNewActivity(array_replace($record, [$end=>'2026-10-26 12:00:00']), $now), 'Paid/grace boundary is exclusive');
    $check(!P::allowsNewActivity(array_replace($record, [$end=>null]), $now), 'No implicit unlimited paid/grace interval');
}
foreach ([null, '2027-02-30 00:00:00', '2027-01-01', [], 'tomorrow'] as $bad) {
    $check(!P::allowsNewActivity(array_replace($subscription, ['trial_ends_at'=>$bad]), $now), 'Invalid stored dates fail closed');
}
$check(!P::allowsNewActivity($subscription+['cancel_at'=>'2026-10-26 12:00:00'], $now), 'Scheduled cancellation takes effect at boundary');
$check(P::hasFeature($subscription, ['sms_reminders'=>true], 'sms_reminders', $now), 'Explicit tier entitlement');
$check(!P::hasFeature($subscription, ['sms_reminders'=>'true'], 'sms_reminders', $now), 'Nonboolean features cannot grant access');
$check(!P::hasFeature($subscription, [], 'sms_reminders', $now), 'Absent features are denied');
$check(P::budgetAllows(1000, 800, 150, 50), 'Exact allowance boundary is permitted');
$check(!P::budgetAllows(1000, 800, 150, 51), 'Reservations count against allowance');
$check(!P::budgetAllows(1000, 1001, 0, 1), 'Actual provider overage stops new spending');
$check(!P::budgetAllows(1000, 0, 0, 0), 'Zero estimate cannot bypass metering');
$check(!P::budgetAllows(1000, 0, 0, PHP_INT_MAX), 'Large estimates cannot overflow');
$check(P::month(new DateTimeImmutable('2026-11-01T03:59:59Z'), 'America/Toronto') === '2026-10-01', 'Local calendar rather than UTC month');
$check(P::month(new DateTimeImmutable('2026-11-01T04:00:00Z'), 'America/Toronto') === '2026-11-01', 'Month rollover has no carryover');
echo "$checks subscription/trial/budget policy checks passed.\n";
