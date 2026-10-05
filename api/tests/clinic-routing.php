<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\ClinicContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Http\Request;
use Wellness\Service\AvailabilityService;
use Wellness\Service\CatalogService;
use Wellness\Service\CustomerOnboarding;

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function denied(callable $action, int $status, string $code): void {
    try { $action(); } catch (ApiException $error) { check($error->status === $status && $error->errorCode === $code, 'Unexpected denial.'); return; }
    throw new RuntimeException('Expected denial was not enforced.');
}
function config(array $map): Config {
    return new Config('test', false, 'test', [], 'unused', 9, 'unused', 'unused', '', '', '', '', 300,
        customerOnboardingEnabled: true, customerClinicId: 1, clinicHostMap: $map);
}

final class RoutingStatement extends PDOStatement
{
    public function __construct(private readonly RoutingPDO $db, private readonly string $sql) {}
    public function execute(?array $params = null): bool { $this->db->calls[] = [$this->sql, $params ?? []]; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function fetchColumn(int $column = 0): mixed {
        $params = end($this->db->calls)[1];
        return in_array($params['clinic'] ?? 0, [1, 2], true) ? $params['clinic'] : false;
    }
}
final class RoutingPDO extends PDO
{
    public array $calls = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new RoutingStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = new RoutingStatement($this, $query); $statement->execute(); return $statement;
    }
}
$pdo = new RoutingPDO();
$reflection = new ReflectionClass(Database::class);
$database = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('connection')->setValue($database, $pdo);
$config = config(['a.test' => 1, 'b.test' => 2, 'inactive.test' => 3, 'localhost:8080' => 1]);
$request = new Request('GET', '/api/v1/site-config', ['clinic_id' => 2], ['host' => 'A.TEST.', 'x-forwarded-host' => 'b.test', 'forwarded' => 'host=b.test'], [], 'test-routing');
$a = ClinicContext::resolve($config, $database, $request);
check($a->clinicId === 1 && $a->host === 'a.test', 'Query or proxy header changed clinic context.');
$b = ClinicContext::forHost($config, 'b.test');
check($b->clinicId === 2, 'Second synthetic host did not resolve separately.');
check(ClinicContext::forHost($config, 'localhost:8080')->clinicId === 1, 'Development authority did not resolve.');
denied(fn() => ClinicContext::forHost($config, 'localhost:8081'), 404, 'clinic_host_not_found');
denied(fn() => ClinicContext::forHost($config, 'portal.copihue.ca'), 404, 'clinic_host_not_found');
denied(fn() => ClinicContext::resolve($config, $database, new Request('GET', '/', [], ['host' => 'inactive.test'], [], 'test-routing')), 404, 'clinic_host_not_found');
foreach (['', 'a.test/path', 'a.test@b.test', 'a.test, b.test', ' a.test', 'a..test', '-a.test', 'a.test:0', 'a.test:65536'] as $host) {
    denied(fn() => ClinicContext::forHost($config, $host), 400, 'invalid_host');
}
denied(fn() => ClinicContext::forHost(config([]), 'a.test'), 503, 'clinic_routing_not_configured');
denied(fn() => ClinicContext::forHost(config(['a.test' => '1']), 'a.test'), 503, 'clinic_routing_not_configured');
denied(fn() => ClinicContext::forHost(config(['a.test' => 1, 'A.TEST.' => 2]), 'a.test'), 503, 'clinic_routing_not_configured');
$actor = new AuthContext(1, 1, 'subject', 'test@example.test', 'Test', 'staff', ['practitioner']);
check($a->assertActor($actor) === $actor, 'Existing clinic actor was rejected.');
denied(fn() => $b->assertActor($actor), 403, 'clinic_access_denied');
new CustomerOnboarding($pdo, $config, 1);
denied(fn() => new CustomerOnboarding($pdo, $config, 2), 503, 'onboarding_unavailable');

// Capture every catalogue query, including direct slug/image and booking lists.
// Real MySQL row-level isolation remains a separate hosted integration gate.
foreach ([1, 2] as $id) {
    $catalog = new CatalogService($database, $id);
    foreach ([fn() => $catalog->siteConfig(), fn() => $catalog->brandAsset('logo'), fn() => $catalog->locations(),
        fn() => $catalog->services(), fn() => $catalog->services(7), fn() => $catalog->publicServices(),
        fn() => $catalog->publicService('massage'), fn() => $catalog->publicPractitioners(), fn() => $catalog->publicPractitioner('alex'),
        fn() => $catalog->practitioners(), fn() => $catalog->practitioners(7), fn() => $catalog->team(), fn() => $catalog->teamImage('alex')] as $action) {
        $pdo->calls = [];
        try { $action(); } catch (ApiException $error) { check(in_array($error->status, [404, 503], true), 'Unexpected empty fixture error.'); }
        check($pdo->calls !== [], 'Catalogue path made no scoped query.');
        foreach ($pdo->calls as [$sql, $params]) {
            check(!str_contains($sql, 'ORDER BY c.id LIMIT 1') && !str_contains($sql, "status='active' ORDER BY id LIMIT 1"), 'First-clinic fallback remains.');
            check(preg_match('/(?:clinic_id|c\.id)=' . $id . '\b/', $sql) === 1, 'Catalogue query did not scope to selected clinic.');
        }
    }
    $pdo->calls = [];
    $availability = new AvailabilityService($database, $id);
    denied(fn() => $availability->publicSearch(['service_id' => 7, 'practitioner_id' => 7, 'location_id' => 7, 'date_from' => '2027-01-01', 'date_to' => '2027-01-01', 'clinic_id' => 99]), 404, 'service_not_available');
    check($pdo->calls[0][1]['public_clinic'] === $id && str_contains($pdo->calls[0][0], 's.clinic_id=:public_clinic'), 'Public availability ignored clinic context.');
}
denied(fn() => (new AvailabilityService($database))->publicSearch([]), 503, 'clinic_not_configured');
echo "{$checks} clinic routing checks passed.\n";
