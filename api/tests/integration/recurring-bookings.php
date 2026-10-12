<?php
declare(strict_types=1);
// Disposable localhost schema only. No .env or hosted client records are read.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\{AddressCoverageService,AuditLogger,BookingService,RecurrencePattern,RecurringBookingService};
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Set RECURRING_TEST_ALLOW_CREATE=true to create a synthetic local database.');
$port=(int)(getenv('RECURRING_TEST_PORT')?:13317);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('RECURRING_TEST_USER')?:'root',getenv('RECURRING_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_recurring_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");$pdo->exec("SET time_zone='+00:00'");
echo "Synthetic database retained for inspection: $name\n";
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$schema=preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema);
// Rehearse migration 035 against the prior schema, including a legacy series.
$offset=strpos($schema,'CREATE TABLE recurring_booking_requests');if($offset===false)throw new RuntimeException('Cannot isolate migration 035.');
$schema=substr($schema,0,$offset);$schema=str_replace('CREATE TABLE recurring_series (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,clinic_id BIGINT UNSIGNED NULL,timezone VARCHAR(64) NULL,','CREATE TABLE recurring_series (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,',$schema);
$schema=preg_replace_callback('/CREATE TABLE recurring_series .*?ENGINE=InnoDB;/s',static fn($match)=>str_replace('FOREIGN KEY(clinic_id) REFERENCES clinics(id),','',$match[0]),$schema);
$pdo->exec($schema);
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic A'),(2,'Synthetic B');
 INSERT INTO locations(id,clinic_id,name,timezone) VALUES(1,1,'Main','America/Toronto');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(1,1,'admin@example.test','Admin','staff','active'),(2,1,'practitioner@example.test','Practitioner','staff','active'),(8,1,'client@example.test','Client','client','active'),(9,1,'other@example.test','Other','client','active'),(10,2,'other-clinic@example.test','Other clinic','client','active');
 INSERT INTO practitioners(id,user_id,discipline,booking_mode) VALUES(3,2,'Massage','practitioner_managed');
 INSERT INTO services(id,clinic_id,slug,name,price_cents,requires_room,recurrence_allowed,lead_time_minutes,booking_horizon_days) VALUES(4,1,'massage','Massage',10000,0,1,0,365);
 INSERT INTO service_duration_options(id,service_id,duration_minutes,price_cents) VALUES(8,4,60,10000);
 INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(3,1);
 INSERT INTO service_locations(service_id,location_id) VALUES(4,1);
 INSERT INTO practitioner_services(practitioner_id,service_id,offers_clinic) VALUES(3,4,1);
 INSERT INTO recurring_series(id,client_id,practitioner_id,service_id,frequency,series_start) VALUES(1,8,3,4,'weekly','2020-01-01');");
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/035_recurring_booking_requests.sql'));
for($day=1;$day<=7;$day++)$pdo->prepare("INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from) VALUES(3,1,?,'09:00:00','18:00:00','2020-01-01')")->execute([$day]);
$_ENV['MAIL_ENABLED']='false';
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','','workforce','api','scope',300);$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$bookings=new BookingService($database,new AuditLogger($database),new AddressCoverageService($database,$config));$series=new RecurringBookingService($database,$bookings);
$client=new AuthContext(8,1,'','','','client',[]);$admin=new AuthContext(1,1,'','','','staff',['super_admin']);$checks=0;
$check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Real SQL recurrence assertion failed');$checks++;};
$denies=function(callable $action,string $code)use($check){try{$action();}catch(ApiException $e){$check($e->errorCode===$code);return;}throw new RuntimeException('Expected '.$code);};
$counts=fn()=>array_map('intval',$pdo->query('SELECT (SELECT COUNT(*) FROM appointments) appointments,(SELECT COUNT(*) FROM notification_events) notifications,(SELECT COUNT(*) FROM audit_logs) audits,(SELECT COUNT(*) FROM recurring_booking_requests) requests')->fetch());
$check((int)$pdo->query('SELECT clinic_id FROM recurring_series WHERE id=1')->fetchColumn()===1);
$start=(new DateTimeImmutable('now',new DateTimeZone('America/Toronto')))->modify('+14 days')->setTime(10,0)->format('Y-m-d\TH:i:sP');
$body=['delivery_mode'=>'clinic','location_id'=>1,'practitioner_id'=>3,'service_id'=>4,'duration_option_id'=>8,'starts_at'=>$start,'quoted_base_price_cents'=>10000,'quoted_mobile_fee_cents'=>0,'idempotency_key'=>'integration-series-create','recurrence'=>['frequency'=>'weekly','count'=>3]];
$before=$counts();$preview=$series->create($client,$body,'test',false);$check($preview['ready']&&$counts()===$before);
$denies(fn()=>$series->create($client,$body+['preview_token'=>'wrong'],'test',true),'series_preview_changed');$check($counts()===$before);
$dates=RecurrencePattern::dates($start,'America/Toronto',$body['recurrence']);
$blockBody=array_replace($body,['client_id'=>9,'starts_at'=>$dates[1]['starts_at'],'idempotency_key'=>'integration-blocker']);unset($blockBody['recurrence']);
$blocked=$bookings->create($admin,$blockBody,'test');$before=$counts();
$conflicts=$series->create($client,$body,'test',false);$check(!$conflicts['ready']&&!$conflicts['items'][1]['ok']&&$conflicts['items'][0]['ok']&&$conflicts['items'][2]['ok']&&$counts()===$before);
$failed=$series->create($client,$body+['preview_token'=>$preview['preview_token']],'test',true);$check(!$failed['applied']&&$counts()===$before);
$bookings->update($admin,(int)$blocked['id'],['action'=>'cancel','version'=>1],'test');
$preview=$series->create($client,$body,'test',false);$saved=$series->create($client,$body+['preview_token'=>$preview['preview_token']],'test',true);$check($saved['applied']&&count($saved['items'])===3);
$before=$counts();$check($series->create($client,$body,'test',true)==$saved&&$counts()===$before);
$denies(fn()=>$series->create(new AuthContext(9,1,'','','','client',[]),$body,'test',true),'idempotency_conflict');
$denies(fn()=>$series->view(new AuthContext(9,1,'','','','client',[]),(int)$saved['series_id']),'appointment_not_found');
$denies(fn()=>$series->view(new AuthContext(10,2,'','','','client',[]),(int)$saved['series_id']),'series_not_found');
$denies(fn()=>$series->create($client,$body+['client_id'=>9],'test',false),'validation_error');
$pdo->exec('UPDATE services SET recurrence_allowed=0 WHERE id=4');$denies(fn()=>$series->create($client,array_replace($body,['idempotency_key'=>'not-eligible-request']),'test',false),'recurrence_not_allowed');$pdo->exec('UPDATE services SET recurrence_allowed=1 WHERE id=4');
$ids=array_column($saved['items'],'appointment_id');$bookings->update($client,$ids[0],['action'=>'cancel','version'=>1,'expected_cancellation_fee_cents'=>0],'test');
$check((int)$pdo->query('SELECT version FROM appointments WHERE id='.$ids[1])->fetchColumn()===1&&(int)$pdo->query('SELECT version FROM appointments WHERE id='.$ids[2])->fetchColumn()===1);
$view=$series->view($client,(int)$saved['series_id']);$check(count($view['items'])===2);
$changes=['action'=>'reschedule','idempotency_key'=>'integration-series-reschedule','items'=>array_map(static fn($item)=>['appointment_id'=>$item['appointment_id'],'version'=>$item['version'],'starts_at'=>(new DateTimeImmutable($item['starts_at'],new DateTimeZone('UTC')))->modify('+1 day +1 hour')->format('Y-m-d\TH:i:s\Z')],$view['items'])];
$before=$counts();$preview=$series->change($client,(int)$saved['series_id'],$changes,'test',false);$check($preview['ready']&&$counts()===$before);
$blockBody['starts_at']=$changes['items'][1]['starts_at'];$blockBody['idempotency_key']='integration-race-blocker';$blocked=$bookings->create($admin,$blockBody,'test');$before=$counts();
$failed=$series->change($client,(int)$saved['series_id'],$changes+['preview_token'=>$preview['preview_token']],'test',true);$check(!$failed['applied']&&!$failed['ready']&&$counts()===$before);
$check((int)$pdo->query('SELECT version FROM appointments WHERE id='.$ids[1])->fetchColumn()===1);
$bookings->update($admin,(int)$blocked['id'],['action'=>'cancel','version'=>1],'test');
$preview=$series->change($client,(int)$saved['series_id'],$changes,'test',false);$changed=$series->change($client,(int)$saved['series_id'],$changes+['preview_token'=>$preview['preview_token']],'test',true);$check($changed['applied']);$before=$counts();
$check($series->change($client,(int)$saved['series_id'],$changes,'test',true)==$changed&&$counts()===$before);
$pdo->exec('UPDATE appointments SET cancellation_window_minutes=43200,cancellation_fee_type=\'fixed\',cancellation_fee_value=2500 WHERE id='.$ids[1]);
$view=$series->view($client,(int)$saved['series_id']);$cancel=['action'=>'cancel','idempotency_key'=>'integration-series-cancel','items'=>array_map(static fn($item)=>['appointment_id'=>$item['appointment_id'],'version'=>$item['version'],'expected_cancellation_fee_cents'=>$item['cancellation_fee_cents']],$view['items'])];
$preview=$series->change($client,(int)$saved['series_id'],$cancel,'test',false);$check($preview['ready']);
$pdo->exec('UPDATE appointments SET cancellation_fee_value=3000 WHERE id='.$ids[1]);$before=$counts();
$denies(fn()=>$series->change($client,(int)$saved['series_id'],$cancel+['preview_token'=>$preview['preview_token']],'test',true),'cancellation_fee_changed');$check($counts()===$before);
$view=$series->view($client,(int)$saved['series_id']);$cancel['items']=array_map(static fn($item)=>['appointment_id'=>$item['appointment_id'],'version'=>$item['version'],'expected_cancellation_fee_cents'=>$item['cancellation_fee_cents']],$view['items']);
$preview=$series->change($client,(int)$saved['series_id'],$cancel,'test',false);$changed=$series->change($client,(int)$saved['series_id'],$cancel+['preview_token'=>$preview['preview_token']],'test',true);$check($changed['applied']);
$check((int)$pdo->query('SELECT cancellation_fee_cents FROM appointments WHERE id='.$ids[1])->fetchColumn()===3000);
$check((int)$pdo->query('SELECT version FROM appointments WHERE id='.$ids[0])->fetchColumn()===2);$before=$counts();
$check($series->change($client,(int)$saved['series_id'],$cancel,'test',true)==$changed&&$counts()===$before);
$check(!$pdo->inTransaction());
// Real concurrent confirmations through independent PHP processes/connections.
$race=function(array $bodies)use($name):array{
 if(!function_exists('proc_open'))throw new RuntimeException('Concurrent SQL acceptance requires proc_open.');
 $workers=[];$logs=dirname(__DIR__,3).'/.tmp/recurrence-race-'.bin2hex(random_bytes(6));
 if(!is_dir(dirname($logs)))mkdir(dirname($logs),0777,true);
 mkdir($logs);
 try{
  foreach($bodies as $index=>$request){
   $readyFile="$logs/$index.ready";$outputFile="$logs/$index.json";$errorFile="$logs/$index.error";
   $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/recurring-bookings-worker.php',$name,json_encode($request,JSON_THROW_ON_ERROR),$readyFile],[0=>['pipe','r'],1=>['file',$outputFile,'w'],2=>['file',$errorFile,'w']],$pipes);
   if(!is_resource($process))throw new RuntimeException('Could not start recurrence worker.');
   $workers[]=['process'=>$process,'pipes'=>$pipes,'ready'=>$readyFile,'output'=>$outputFile,'error'=>$errorFile];
  }
  $wait=function(bool $ready)use(&$workers):void{
   $deadline=microtime(true)+30;
   do{
    $done=true;
    foreach($workers as &$worker){
     $status=proc_get_status($worker['process']);
     if($ready){if(!is_file($worker['ready'])){if(!$status['running'])throw new RuntimeException('Worker failed before release: '.file_get_contents($worker['error']));$done=false;}}
     elseif($status['running'])$done=false;
    }unset($worker);
    if($done)return;
    usleep(10000);
   }while(microtime(true)<$deadline);
   throw new RuntimeException('Concurrent recurrence workers timed out.');
  };
  $wait(true);
  foreach($workers as $worker){if(fwrite($worker['pipes'][0],"GO\n")!==3||!fflush($worker['pipes'][0]))throw new RuntimeException('Could not release recurrence worker.');}
  $wait(false);$results=[];
  foreach($workers as $worker){$error=file_get_contents($worker['error']);if($error!=='')throw new RuntimeException('Recurrence worker failed: '.$error);$results[]=json_decode(file_get_contents($worker['output']),true,32,JSON_THROW_ON_ERROR);}
  return $results;
 }finally{
  foreach($workers as $worker){if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);foreach($worker['pipes'] as $pipe)fclose($pipe);proc_close($worker['process']);}
 }
};
$raceBody=array_replace($body,['starts_at'=>(new DateTimeImmutable($start))->modify('+42 days')->format('Y-m-d\TH:i:sP'),'idempotency_key'=>'concurrent-identical-series']);
$preview=$series->create($client,$raceBody,'test',false);$check($preview['ready']);$raceBody['preview_token']=$preview['preview_token'];
$before=$counts();$identical=$race([$raceBody,$raceBody]);$after=$counts();
$check($identical[0]['applied']&&$identical[0]===$identical[1]);
$check($after['appointments']===$before['appointments']+3&&$after['requests']===$before['requests']+1&&$after['notifications']===$before['notifications']+3);
$raceBody=array_replace($body,['starts_at'=>(new DateTimeImmutable($start))->modify('+84 days')->format('Y-m-d\TH:i:sP'),'idempotency_key'=>'concurrent-competing-series-a']);
$other=array_replace($raceBody,['idempotency_key'=>'concurrent-competing-series-b']);
$raceBody['preview_token']=$series->create($client,$raceBody,'test',false)['preview_token'];$other['preview_token']=$series->create($client,$other,'test',false)['preview_token'];
$before=$counts();$competing=$race([$raceBody,$other]);$after=$counts();
$check(count(array_filter($competing,static fn($result)=>$result['applied']))===1);
$loser=array_values(array_filter($competing,static fn($result)=>!$result['applied']))[0];
$check(!$loser['ready']&&count(array_filter($loser['items'],static fn($item)=>!$item['ok']&&$item['code']==='schedule_conflict'))===3);
$check($after['appointments']===$before['appointments']+3&&$after['requests']===$before['requests']+1&&$after['notifications']===$before['notifications']+3);
echo "Real SQL recurring booking acceptance: $checks checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
