<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Database;
use Wellness\Service\AvailabilityService;

final class DstStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private readonly string $sql) {}
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'FROM services s')) $this->rows = [[
            'offers_mobile' => 1, 'offers_clinic' => 1, 'requires_room' => 0,
            'travel_buffer_minutes' => 0, 'mobile_fee_cents' => 0, 'base_price_cents' => 10000,
            'lead_time_minutes' => 0, 'booking_horizon_days' => 365,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'duration_option_id' => 1, 'duration_minutes' => 60, 'timezone' => 'America/Toronto',
        ]];
        elseif (str_contains($this->sql, 'FROM availability_rules')) $this->rows = [['start_time' => '00:00:00', 'end_time' => '04:00:00']];
        else $this->rows = [];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0][$column] ?? false; }
}
final class DstPDO extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new DstStatement($query); }
}

$reflection = new ReflectionClass(Database::class);
$database = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('connection')->setValue($database, new DstPDO());
$engine = new AvailabilityService($database);
$now = new DateTimeImmutable('now', new DateTimeZone('America/Toronto'));
$nextSunday = static function (int $month, int $ordinal) use ($now): string {
    for ($year = (int)$now->format('Y'); $year <= (int)$now->format('Y') + 2; $year++) {
        $date = new DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month), new DateTimeZone('America/Toronto'));
        $first = $date->modify('first sunday of this month');
        $candidate = $first->modify('+' . ($ordinal - 1) . ' weeks');
        if ($candidate > $now->modify('+1 day') && $candidate < $now->modify('+365 days')) return $candidate->format('Y-m-d');
    }
    throw new RuntimeException('No DST test date falls inside the booking horizon.');
};
$slots = static function (string $date) use ($engine): array {
    return $engine->search(['service_id' => 1, 'practitioner_id' => 1, 'location_id' => 1, 'delivery_mode' => 'clinic', 'date_from' => $date, 'date_to' => $date])['availability'];
};
$fall = $slots($nextSunday(11, 1));
$oneAm = array_values(array_filter($fall, static fn(array $slot): bool => substr($slot['starts_at'], 11, 5) === '01:00'));
if (count($oneAm) !== 2 || $oneAm[0]['starts_at'] === $oneAm[1]['starts_at']) throw new RuntimeException('Fall-back 1 a.m. slots must have two distinct UTC offsets.');
$spring = $slots($nextSunday(3, 2));
if (!$spring || !array_filter($spring, static fn(array $slot): bool => substr($slot['starts_at'], 11, 5) === '03:00')) throw new RuntimeException('Spring-forward test produced no valid post-transition slot.');
if (array_filter($spring, static fn(array $slot): bool => substr($slot['starts_at'], 11, 2) === '02')) throw new RuntimeException('Spring-forward offered a nonexistent 2 a.m. slot.');
foreach ([$fall, $spring] as $daySlots) {
    $timestamps = array_map(static fn(array $slot): int => strtotime($slot['starts_at']), $daySlots);
    $ordered = $timestamps;
    sort($ordered);
    if ($timestamps !== array_values(array_unique($timestamps)) || $timestamps !== $ordered) throw new RuntimeException('DST slots are duplicate or out of chronological order.');
}
echo "DST availability checks passed.\n";
