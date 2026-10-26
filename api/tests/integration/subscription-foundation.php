<?php
declare(strict_types=1);
// Disposable localhost databases only. Never reads .env or contacts a message/payment provider.
require dirname(__DIR__,2).'/vendor/autoload.php';

use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\SmsBudgetLedger;

if (getenv('RECURRING_TEST_ALLOW_CREATE') !== 'true') throw new RuntimeException('Explicit disposable database setup is required.');
$port = (int)(getenv('RECURRING_TEST_PORT') ?: 13317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", getenv('RECURRING_TEST_USER') ?: 'root', getenv('RECURRING_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
]);
$worker = ($argv[1] ?? '') === 'worker';
$name = $worker ? ($argv[2] ?? '') : 'wellness_subscription_test_'.bin2hex(random_bytes(6));
if (!preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Invalid synthetic database name.');
if (!$worker) $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");
$pdo->exec("SET time_zone='+00:00'");
$config = new Config('test', false, 'test', [], '127.0.0.1', $port, $name, 'root', '', '', '', '', 300);
$database = new Database($config);
(new ReflectionProperty(Database::class,'connection'))->setValue($database, $pdo);
$ledger = new SmsBudgetLedger($database);
$now = new DateTimeImmutable('2026-10-26T12:00:00Z');
if ($worker) {
    try {
        $ledger->reserve(1, (int)$argv[3], $argv[4], 600, new DateTimeImmutable($argv[5]));
        echo 'reserved';
    } catch (ApiException $e) {
        if ($e->errorCode !== 'sms_budget_exhausted') throw $e;
        echo 'exhausted';
    }
    exit;
}
echo "Synthetic database retained: $name\n";
$schema = file_get_contents(dirname(__DIR__,2).'/database/schema.sql');
$offset = strpos($schema, 'CREATE TABLE form_library_entries');
if ($offset === false) throw new RuntimeException('Cannot isolate schema baseline.');
$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m', '', substr($schema,0,$offset)));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/053_subscription_foundation.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/054_billing_administration.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic Wellnexa'),(2,'Foreign clinic')");
for ($id=1; $id<=14; $id++) {
    $clinic = $id===13 ? 2 : 1;
    $pdo->prepare("INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(?,?,?,?,'staff','active')")->execute([$id,$clinic,"member$id@example.test","Member $id"]);
    $pdo->prepare("INSERT INTO product_identities(id,adapter,issuer,subject) VALUES(?,'synthetic','test',?)")->execute([$id,"subject-$id"]);
    $pdo->prepare("INSERT INTO staff_memberships(id,identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,?,'active')")->execute([$id,$id,$clinic,$id]);
    if ($id!==14) $pdo->prepare("INSERT INTO practitioners(user_id,discipline) VALUES(?,'Synthetic')")->execute([$id]);
}
$pdo->exec("INSERT INTO sms_budget_pools(id,clinic_id,status) VALUES(1,1,'active'),(2,2,'inactive')");
$checks=0;
$check=function(bool $ok,string $why)use(&$checks):void { if (!$ok) throw new RuntimeException($why); $checks++; };
$deny=function(callable $action,string $code)use($check):void {
    try { $action(); } catch (ApiException $e) { $check($e->errorCode===$code,"Expected $code, got {$e->errorCode}"); return; }
    throw new RuntimeException("Expected $code");
};
$period=fn(string $month='2026-10-01')=>$pdo->query("SELECT * FROM sms_budget_periods WHERE pool_id=1 AND period_start='$month'")->fetch();
for ($id=1; $id<=10; $id++) $ledger->enroll(1,$id);
$ledger->enroll(1,1);
$check((int)$pdo->query('SELECT COUNT(*) FROM sms_budget_members')->fetchColumn()===10,'Idempotent enrollment');
$deny(fn()=>$ledger->enroll(1,11),'sms_cohort_full');
$deny(fn()=>$ledger->enroll(1,13),'sms_membership_ineligible');
$deny(fn()=>$ledger->enroll(1,14),'sms_membership_ineligible');
$deny(fn()=>$ledger->enroll(2,13),'sms_pool_inactive');
$deny(fn()=>$ledger->reserve(1,11,'not-enrolled',1,$now),'sms_not_enrolled');
$first=$ledger->reserve(1,1,'reminder-1',600,$now);
$check($first['created']===true,'New delivery reserves once');
$check($ledger->reserve(1,1,'reminder-1',600,$now)['created']===false,'Retry returns existing reference');
$deny(fn()=>$ledger->reserve(1,1,'reminder-1',601,$now),'sms_reference_conflict');
$deny(fn()=>$ledger->reserve(1,2,'reminder-1',600,$now),'sms_reference_conflict');
$deny(fn()=>$ledger->reserve(1,1,'too-large',401,$now),'sms_budget_exhausted');
$check((int)$period()['reserved_cents']===600,'Failed reservation rolls back entirely');
$ledger->reserve(1,2,'reminder-2',1000,$now);
$ledger->sharedCost(1,'number-rental-and-fees',8400,$now);
$ledger->sharedCost(1,'number-rental-and-fees',8400,$now);
$check((int)$period()['spent_cents']===8400,'Shared provider costs count once against global cap');
$deny(fn()=>$ledger->reserve(1,3,'global-cap',1,$now),'sms_budget_exhausted');
$check($ledger->claim(1,1,'reminder-1',$now),'Provider work can be claimed once');
$check(!$ledger->claim(1,1,'reminder-1',$now),'No second provider submission on replay');
$deny(fn()=>$ledger->release(1,1,'reminder-1'),'sms_invalid_transition');
$deny(fn()=>$ledger->settle(1,2,'reminder-1',500),'sms_entry_not_found');
$ledger->settle(1,1,'reminder-1',500);
$ledger->settle(1,1,'reminder-1',500);
$deny(fn()=>$ledger->settle(1,1,'reminder-1',501),'sms_reference_conflict');
$check((int)$period()['reserved_cents']===1000 && (int)$period()['spent_cents']===8900,'Reconcile actual cost without duplicate charges');
$ledger->reserve(1,1,'release-me',100,$now);
$ledger->release(1,1,'release-me');
$ledger->release(1,1,'release-me');
$check((int)$period()['reserved_cents']===1000,'Definitive no-send releases estimate once');
$check(!$ledger->claim(1,1,'release-me',$now),'Released reference cannot be sent');
$ledger->claim(1,2,'reminder-2',$now);
$pdo->exec("UPDATE sms_budget_pools SET status='inactive' WHERE id=1; UPDATE staff_memberships SET status='revoked' WHERE id=2");
$ledger->settle(1,2,'reminder-2',1100);
$check((int)$period()['spent_cents']===10000 && (int)$period()['reserved_cents']===0,'Settlement survives deactivation and records provider over-estimate honestly');
$pdo->exec("UPDATE sms_budget_pools SET status='active' WHERE id=1; UPDATE staff_memberships SET status='active' WHERE id=2");
$deny(fn()=>$ledger->reserve(1,2,'over-member-cap',1,$now),'sms_budget_exhausted');
$ledger->sharedCost(1,'additional-provider-fee',1,$now);
$deny(fn()=>$ledger->reserve(1,3,'over-global-cap',1,$now),'sms_budget_exhausted');
$next=new DateTimeImmutable('2026-11-01T04:00:00Z');
$ledger->reserve(1,1,'next-month',600,$next);
$check((int)$period('2026-11-01')['reserved_cents']===600 && (int)$period('2026-11-01')['spent_cents']===0,'New calendar month has fresh allowance');
$deny(fn()=>$ledger->claim(1,1,'next-month',new DateTimeImmutable('2026-12-01T05:00:00Z')),'sms_reservation_expired');
$ledger->claim(1,1,'next-month',$next);
$ledger->settle(1,1,'next-month',500);
$check((int)$period('2026-11-01')['spent_cents']===500,'Settlement uses reservation period');
$ledger->reserve(1,3,'pending-before-overhead',100,$next);
$ledger->sharedCost(1,'late-overhead',9401,$next);
$deny(fn()=>$ledger->claim(1,3,'pending-before-overhead',$next),'sms_budget_exhausted');
$ledger->release(1,3,'pending-before-overhead');
$pdo->exec("UPDATE product_identities SET status='inactive' WHERE id=3");
$deny(fn()=>$ledger->reserve(1,3,'inactive-identity',1,$next),'sms_membership_ineligible');
$pdo->exec("UPDATE product_identities SET status='active' WHERE id=3");
try {
    $pdo->exec('INSERT INTO billing_accounts(clinic_id,membership_id) VALUES(1,13)');
    throw new RuntimeException('Cross-clinic billing account accepted');
} catch (PDOException $e) { $check($e->getCode()==='23000','Composite billing scope foreign key'); }
$pdo->exec('INSERT INTO billing_accounts(clinic_id) VALUES(1)');
try { $pdo->exec('INSERT INTO billing_accounts(clinic_id) VALUES(1)'); throw new RuntimeException('Duplicate tenant billing account accepted'); }
catch (PDOException $e) { $check($e->getCode()==='23000','One tenant billing account'); }

// Actual concurrent PHP processes: only one 600-cent reservation fits a 1,000-cent cap.
$race=function(array $members,string $date)use($name):array {
$jobs=[];
foreach ($members as $index=>$member) {
    $pipes=[];
    $process=proc_open([PHP_BINARY,__FILE__,'worker',$name,(string)$member,'race-'.$date.'-'.$index,$date],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch SQL concurrency worker');
    fclose($pipes[0]); $jobs[]=[$process,$pipes];
}
$results=[];
foreach ($jobs as [$process,$pipes]) {
    $results[]=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Concurrency worker failed: '.$errors);
}
sort($results);
return $results;
};
$results=$race([4,4],'2026-12-15');
$check($results===['exhausted','reserved'],'Concurrent sends cannot overspend a practitioner allowance');
$check((int)$period('2026-12-01')['reserved_cents']===600,'Concurrent reservation totals are consistent');
$pdo->exec('UPDATE sms_budget_pools SET monthly_limit_cents=1000 WHERE id=1');
$results=$race([5,6],'2027-01-15');
$check($results===['exhausted','reserved'],'Concurrent practitioners cannot overspend the shared cap');
$check((int)$period('2027-01-01')['reserved_cents']===600,'Global cap counts reservations across members');
echo "$checks subscription/SMS ledger SQL checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
