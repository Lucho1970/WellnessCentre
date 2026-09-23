<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\BookingService;

$actor = static fn(string $type, array $roles): AuthContext => new AuthContext(1, 1, '', '', '', $type, $roles);
BookingService::authorizePractitionerCalendar($actor('staff', ['practitioner']));
BookingService::authorizePractitionerCalendar($actor('staff', ['super_admin', 'practitioner']));
foreach ([$actor('staff', ['super_admin']), $actor('staff', ['reception']), $actor('client', ['practitioner'])] as $denied) {
    try { BookingService::authorizePractitionerCalendar($denied); throw new RuntimeException('Unauthorized calendar access accepted.'); }
    catch (ApiException $e) { if ($e->status !== 403) throw $e; }
}
$range = BookingService::calendarRange(['start' => '2026-09-01T00:00:00Z', 'end' => '2026-10-13T00:00:00Z']);
if ($range !== ['2026-09-01 00:00:00', '2026-10-13 00:00:00']) throw new RuntimeException('UTC calendar boundary conversion failed.');
foreach ([
    ['start' => '2026-09-01', 'end' => '2026-09-02T00:00:00Z'],
    ['start' => '2026-02-30T00:00:00Z', 'end' => '2026-03-01T00:00:00Z'],
    ['start' => '2026-09-02T00:00:00Z', 'end' => '2026-09-01T00:00:00Z'],
    ['start' => '2026-09-01T00:00:00Z', 'end' => '2026-11-04T00:00:00Z'],
] as $invalid) {
    try { BookingService::calendarRange($invalid); throw new RuntimeException('Invalid calendar range accepted.'); }
    catch (ApiException $e) { if ($e->status !== 422) throw $e; }
}
echo "Practitioner calendar authorization and range tests passed.\n";
