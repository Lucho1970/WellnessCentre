<?php
declare(strict_types=1);
// Creates a disposable synthetic localhost database; never reads the application .env.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AppointmentActionLinks;
use Wellness\Service\AuditLogger;
if(getenv('APPOINTMENT_LINK_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Set APPOINTMENT_LINK_TEST_ALLOW_CREATE=true for a synthetic local database.');
$port=(int)(getenv('APPOINTMENT_LINK_TEST_PORT')?:13317);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('APPOINTMENT_LINK_TEST_USER')?:'root',getenv('APPOINTMENT_LINK_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_action_link_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");
echo "Synthetic database retained for inspection: $name\n";
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');
$schema=preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema);
// Rehearse migration 034 itself against the baseline without its appended definition.
$offset=strpos($schema,'-- Apply once, before enabling APPOINTMENT_ACTION_LINKS_ENABLED.');
if($offset===false)throw new RuntimeException('Cannot isolate the migration rehearsal.');
$pdo->exec(substr($schema,0,$offset));$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/034_appointment_action_links.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic clinic A'),(2,'Synthetic clinic B');
 INSERT INTO locations(id,clinic_id,name) VALUES(1,1,'Main');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES
 (1,1,'admin@example.test','Admin','staff','active'),(2,1,'practitioner@example.test','Practitioner','staff','active'),
 (8,1,'client@example.test','Client','client','active'),(9,1,'other@example.test','Other','client','active'),(10,2,'clinic-b@example.test','Other clinic','client','active');
 INSERT INTO practitioners(id,user_id,discipline,booking_mode) VALUES(2,2,'Massage','practitioner_managed');
 INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(1,1,'massage','Massage',10000);
 INSERT INTO service_duration_options(id,service_id,duration_minutes,price_cents) VALUES(1,1,60,10000);
 INSERT INTO appointments(id,clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by,base_price_cents)
 VALUES(71,1,1,8,2,1,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 49 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 49 HOUR),'confirmed','admin',1,10000);
 INSERT INTO notification_events(id,clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at)
 VALUES(4,1,71,8,'client@example.test','booking_confirmation','email','sending',UTC_TIMESTAMP());");
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','','workforce','api','scope',300);
$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$service=new AppointmentActionLinks($pdo,true,new AuditLogger($database));
$client=new AuthContext(8,1,'','','','client',[]);$admin=new AuthContext(1,1,'','','','staff',['super_admin']);$checks=0;
$check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Real SQL appointment link assertion failed');$checks++;};
$deny=function(callable $f,int $status=404)use($check){try{$f();}catch(ApiException $e){$check($e->status===$status);return;}throw new RuntimeException('Expected denial');};
$event=['id'=>4,'version'=>1,'event_code'=>'booking_confirmation'];
$url=$service->issue($event,'https://clinic.example/client');$check(is_string($url));$token=substr($url,strpos($url,'#token=')+7);
$check((int)$service->resolve($client,['token'=>$token],'test')['appointment']['id']===71);
$stored=$pdo->query('SELECT * FROM appointment_action_links')->fetch();$check($stored['token_hash']===hash('sha256',$token)&&!str_contains(json_encode($stored),$token));
$check(strtotime($stored['expires_at'].' UTC')<=time()+2*86400);
$deny(fn()=>$service->resolve(new AuthContext(9,1,'','','','client',[]),['token'=>$token],'test'));
$deny(fn()=>$service->resolve(new AuthContext(10,2,'','','','client',[]),['token'=>$token],'test'));
$pdo->exec('UPDATE appointment_action_links SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)');$deny(fn()=>$service->resolve($client,['token'=>$token],'test'));
$pdo->exec('UPDATE appointment_action_links SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
$pdo->exec('UPDATE appointments SET version=2 WHERE id=71');$deny(fn()=>$service->resolve($client,['token'=>$token],'test'));$pdo->exec('UPDATE appointments SET version=1 WHERE id=71');
$pdo->exec("UPDATE users SET status='inactive' WHERE id=8");$deny(fn()=>$service->resolve($client,['token'=>$token],'test'));$pdo->exec("UPDATE users SET status='active' WHERE id=8");
$pdo->exec("UPDATE appointments SET status='canceled_by_client' WHERE id=71");$deny(fn()=>$service->resolve($client,['token'=>$token],'test'));$pdo->exec("UPDATE appointments SET status='confirmed' WHERE id=71");
$check($service->revoke($admin,71,'test')['revoked']);$deny(fn()=>$service->resolve($client,['token'=>$token],'test'));
$deny(fn()=>$service->revoke(new AuthContext(2,1,'','','','staff',['practitioner']),71,'test'),403);
$deny(fn()=>$service->revoke(new AuthContext(1,2,'','','','staff',['super_admin']),71,'test'));
$pdo->exec("UPDATE notification_events SET recipient_address='previous-address@example.test' WHERE id=4");$check($service->issue($event,'https://clinic.example/client')===null);
$metadata=$pdo->query('SELECT metadata FROM audit_logs')->fetchAll();$check(!str_contains(json_encode($metadata),$token));
echo "$checks real MySQL appointment link checks passed.\n";
