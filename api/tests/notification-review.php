<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\NotificationReviewService;

$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(1, 1, '', '', '', $type, $roles);
foreach ([$actor('client', ['clinic_admin']), $actor('staff', ['reception']), $actor('staff', ['practitioner'])] as $denied) {
    try { NotificationReviewService::authorize($denied); throw new RuntimeException('Unauthorized reviewer was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}
NotificationReviewService::authorize($actor('staff', ['clinic_admin']));
NotificationReviewService::authorize($actor('staff', ['super_admin']));
foreach ([
    ['decision' => 'retry', 'outcome' => 'provider_not_sent'],
    ['decision' => 'resolve', 'outcome' => 'provider_accepted'],
    ['decision' => 'resolve', 'outcome' => 'handled_manually'],
    ['decision' => 'resolve', 'outcome' => 'no_longer_needed'],
] as $choice) {
    $body = $choice + ['checked_provider_history' => true, 'checked_recipient' => true];
    if (NotificationReviewService::choice($body) !== $choice) throw new RuntimeException('Valid review choice was rejected.');
}
foreach ([
    ['decision' => 'retry', 'outcome' => 'provider_accepted'],
    ['decision' => 'resolve', 'outcome' => 'provider_not_sent'],
    ['decision' => 'retry', 'outcome' => 'provider_not_sent', 'checked_provider_history' => false, 'checked_recipient' => true],
    ['decision' => 'retry', 'outcome' => 'provider_not_sent', 'checked_provider_history' => true, 'checked_recipient' => false],
] as $choice) {
    try { NotificationReviewService::choice($choice + ['checked_provider_history' => true, 'checked_recipient' => true]); throw new RuntimeException('Unsafe review choice was accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
echo "Notification review authorization and validation tests passed.\n";
