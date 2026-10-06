<?php
declare(strict_types=1);
// Disposable synthetic localhost database only. No application .env is loaded.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AuditLogger;
use Wellness\Service\ClientOverviewService;
if(getenv('CLIENT_OVERVIEW_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Set CLIENT_OVERVIEW_TEST_ALLOW_CREATE=true for a synthetic local database.');
$port=(int)(getenv('CLIENT_OVERVIEW_TEST_PORT')?:13317);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('CLIENT_OVERVIEW_TEST_USER')?:'root',getenv('CLIENT_OVERVIEW_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_client_overview_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");
echo "Synthetic database retained for inspection: $name\n";
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/006_client_merge.sql'));
if(!$pdo->query("SHOW COLUMNS FROM appointments LIKE 'cancellation_fee_cents'")->fetch())$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/015_appointment_cancellation_policy.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic A'),(2,'Synthetic B');
 INSERT INTO locations(id,clinic_id,name) VALUES(1,1,'Main A'),(2,2,'Main B');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES
 (1,1,'admin@example.test','Admin','staff','active'),(2,1,'doctor@example.test','Practitioner A','staff','active'),
 (3,1,'inactive@example.test','Inactive practitioner','staff','inactive'),(4,2,'other@example.test','Other practitioner','staff','active'),
 (8,1,'a@example.test','Client A','client','active'),(9,1,'b@example.test','Client B','client','active'),(10,2,'c@example.test','Other clinic client','client','active');
 INSERT INTO practitioners(id,user_id,discipline,booking_mode) VALUES(2,2,'Massage','practitioner_managed'),(3,3,'Massage','practitioner_managed'),(4,4,'Massage','practitioner_managed');
 INSERT INTO roles(id,code,name) VALUES(1,'super_admin','Super admin'),(2,'practitioner','Practitioner');
 INSERT INTO user_roles(user_id,role_id,assigned_by) VALUES(1,1,1),(2,2,1),(3,2,1),(4,2,4);
 INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(1,1,'massage','Massage',10000),(2,2,'other','Other service',10000);
 INSERT INTO service_duration_options(id,service_id,duration_minutes,price_cents) VALUES(1,1,60,10000),(2,2,60,10000);");
$insert=$pdo->prepare("INSERT INTO appointments(id,clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by,base_price_cents) VALUES(?,?,?,?,?,?,?, ?,?,?,?,?,'admin',?,10000)");
$past=gmdate('Y-m-d H:i:s',time()-86400);$pastEnd=gmdate('Y-m-d H:i:s',time()-82800);
$future=gmdate('Y-m-d H:i:s',time()+86400);$futureEnd=gmdate('Y-m-d H:i:s',time()+90000);
$insert->execute([71,1,1,8,2,1,1,$past,$pastEnd,$past,$pastEnd,'completed',1]);
$insert->execute([72,1,1,8,2,1,1,$future,$futureEnd,$future,$futureEnd,'canceled_by_client',1]);
$insert->execute([73,1,1,9,2,1,1,$past,$pastEnd,$past,$pastEnd,'confirmed',1]);
$insert->execute([74,2,2,10,4,2,2,$past,$pastEnd,$past,$pastEnd,'completed',4]);
$pdo->exec("INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id,reason) VALUES(71,'confirmed','completed',2,'Finished synthetic visit'),(73,'requested','confirmed',1,'Other client reason');
 INSERT INTO appointment_reassignments(clinic_id,appointment_id,old_practitioner_id,new_practitioner_id,actor_user_id,reason) VALUES(1,71,3,2,1,'Synthetic coverage');
 INSERT INTO cancellation_adjustments(appointment_id,original_fee_cents,adjusted_fee_cents,reason,authorized_by) VALUES(71,2500,0,'Synthetic waiver',1);");
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','','workforce','api','scope',300);
$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$service=new ClientOverviewService($database,new AuditLogger($database),$config);
$admin=new AuthContext(1,1,'','admin@example.test','Admin','staff',['super_admin']);$checks=0;
$check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Client overview integration assertion failed');$checks++;};
$deny=function(callable $f,int $status)use($check){try{$f();}catch(ApiException $e){$check($e->status===$status);return;}throw new RuntimeException('Expected denial');};
$all=$service->appointments($admin,8,[],'test');$check(count($all['items'])===2&&$all['counts']['total']===2&&$all['counts']['canceled']===1);
$check($service->appointments($admin,8,['view'=>'upcoming'],'test')['items']===[]);
$check(count($service->appointments($admin,8,['view'=>'past'],'test')['items'])===1);
$check((int)$service->appointments($admin,8,['view'=>'canceled'],'test')['items'][0]['id']===72);
$deny(fn()=>$service->appointments($admin,10,[],'test'),404);
$deny(fn()=>$service->appointmentChanges($admin,8,73,[],'test'),404);
$changes=$service->appointmentChanges($admin,8,71,[],'test');$check(count($changes['items'])===3);
$check(array_diff(['status','reassignment','fee_adjustment'],array_column($changes['items'],'kind'))===[]);
$report=$service->practitionerAccess($admin,8,[],'test');$check(count($report['items'])===2);
$byId=array_column($report['items'],null,'practitioner_id');$check($byId[2]['appointment_count']===2&&$byId[2]['configured_access']['client_directory']);
$check(!$byId[3]['configured_access']['staff_configuration_active']&&!$byId[3]['configured_access']['booking_contact']);
$deny(fn()=>$service->practitionerAccess(new AuthContext(2,1,'','','','staff',['practitioner']),8,[],'test'),403);
echo "$checks real MySQL client overview checks passed.\n";
