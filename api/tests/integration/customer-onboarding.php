<?php
declare(strict_types=1);
// Creates synthetic data only in a brand-new random database on localhost.
// No .env is loaded. The database is deliberately retained for failed-test inspection.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Config;
use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\CustomerOnboarding as C;
$port=(int)(getenv('ONBOARDING_TEST_PORT')?:13317);
$db=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('ONBOARDING_TEST_USER')?:'root',getenv('ONBOARDING_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_onboarding_test_'.bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $db->exec("USE `$name`");
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');
$schema=preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema);
$db->exec($schema);
$migration=file_get_contents(dirname(__DIR__,2).'/database/migrations/005_customer_onboarding.sql');
$db->exec($migration); $db->exec($migration); // Rerun is safe.
$db->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/006_client_merge.sql'));
$db->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic clinic'),(2,'Other clinic'); INSERT INTO users(id,clinic_id,email,display_name,user_type,status,given_name,family_name) VALUES(1,1,'staff@example.test','Staff','staff','active','Staff','Test'),(2,1,'existing@example.test','Existing Client','client','active','Existing','Client'),(3,2,'other@example.test','Other Client','client','active','Other','Client'); INSERT INTO client_profiles(user_id,phone,administrative_notes) VALUES(2,'555-0100','PRIVATE NOTE');");
$config=new Config('test',false,'test-key',[],'127.0.0.1',$port,$name,'root','','staff','staff-api','scope',3600,customerOnboardingEnabled:true,customerClinicId:1);
$c=new C($db,$config); $cid='test-correlation';
$staff=new AuthContext(1,1,'staff','staff@example.test','Staff','staff',['super_admin']);
$checks=0; $assert=function(bool $ok,string $msg='Assertion failed')use(&$checks){if(!$ok)throw new RuntimeException($msg);$checks++;};
$reject=function(callable $fn,int $status)use($assert){try{$fn();}catch(ApiException $e){$assert($e->status===$status,"Unexpected status {$e->status}: {$e->errorCode}");return;}throw new RuntimeException('Request should have failed');};
$make=function(string $sub)use($c,$cid){
 $claims=['iss'=>'https://trusted.test','sub'=>$sub,'tid'=>'tenant','oid'=>'11111111-1111-1111-1111-111111111111'];
 $nonce=$c->challenge('127.0.0.1')['nonce']; $proof=$claims+['nonce'=>$nonce,'auth_time'=>time()];
 $session=$c->startSession($claims,$proof,$cid); $id=$c->session($claims,$session['session_token'])['identity_id'];
 return [$id,$claims,$session,$proof];
};
[$a,$ac,$as,$ap]=$make('new'); [$b,$bc,$bs]=$make('existing'); [$d,$dc,$ds]=$make('different');
$assert($c->status($a)['onboarding_status']==='not_linked');
$reject(fn()=>$c->startSession($ac,$ap,$cid),401);
$reject(fn()=>$c->session($bc,$as['session_token']),401);
$assert($db->query("SELECT COUNT(*) FROM customer_sessions WHERE token_hash='".$as['session_token']."'")->fetchColumn()==0,'Raw session stored');
$body=['given_name'=>'New','family_name'=>'Client','email'=>'new@example.test','phone'=>'555-0101','address'=>['address_line1'=>'1 Test Street','city'=>'Test City','province'=>'ON','postal_code'=>'A1A 1A1','country'=>'Canada']];
$assert($c->register($a,$body,$cid)['onboarding_status']==='linked');
$assert($c->register($a,$body,$cid)['onboarding_status']==='linked');
$assert((int)$db->query("SELECT COUNT(*) FROM users WHERE email='new@example.test'")->fetchColumn()===1);
$reject(fn()=>$c->register($d,$body,$cid),409);
$assert($c->status($d)['onboarding_status']==='not_linked');
$invite=$c->invite($staff,2,$cid);
$assert($c->accept($b,['token'=>$invite['token'],'claimant_name'=>'Claimant'],$cid)['onboarding_status']==='pending_review');
$assert($c->accept($b,['token'=>$invite['token'],'claimant_name'=>'Claimant'],$cid)['onboarding_status']==='pending_review');
$reject(fn()=>$c->profile($b,$cid),403); $reject(fn()=>$c->appointments($b,$cid),403);
$reject(fn()=>$c->accept($d,['token'=>$invite['token'],'claimant_name'=>'Thief'],$cid),409);
$reject(fn()=>$c->review($staff,2,$invite['id'],['action'=>'approve'],$cid),422);
$reject(fn()=>$c->review($staff,2,$invite['id'],['action'=>'approve','identity_verified'=>true,'review_code'=>'WRONG'],$cid),422);
$code=$c->status($b)['review_code'];
$assert(!str_contains(json_encode($c->invitations($staff,2)),$code),'Staff endpoint leaked verification code');
$c->review($staff,2,$invite['id'],['action'=>'approve','identity_verified'=>true,'review_code'=>$code],$cid);
$assert($c->status($b)['onboarding_status']==='linked');
$profile=$c->profile($b,$cid); $assert($profile['email']==='existing@example.test');
$assert(!isset($profile['administrative_notes'])&&!isset($profile['user_id'])&&!isset($profile['date_of_birth']));
$reject(fn()=>$c->review($staff,2,$invite['id'],['action'=>'revoke'],$cid),409);
$reject(fn()=>$c->invitations($staff,3),404);
$practitioner=new AuthContext(1,1,'staff','staff@example.test','Staff','staff',['practitioner']);
$reject(fn()=>$c->invite($practitioner,2,$cid),403);
$p=$c->profile($a,$cid); $update=$body+['revision'=>$p['revision']];$update['phone']='555-0102';
$assert($c->saveProfile($a,$update,$cid)['phone']==='555-0102');
$reject(fn()=>$c->saveProfile($a,$update,$cid),409);
$p=$c->profile($a,$cid);$db->exec("UPDATE users SET given_name='Staff edited' WHERE email='new@example.test'");
$reject(fn()=>$c->saveProfile($a,$body+['revision'=>$p['revision']],$cid),409);
$db->exec("INSERT INTO locations(id,clinic_id,name)VALUES(1,1,'Test location'); INSERT INTO practitioners(id,user_id,discipline)VALUES(1,1,'Therapist'); INSERT INTO services(id,clinic_id,name,price_cents)VALUES(1,1,'Test massage',10000)");
$newClient=(int)$db->query("SELECT id FROM users WHERE email='new@example.test'")->fetchColumn();
// Required appointment columns are populated using the real current schema.
$addAppointment=function(int $client)use($db){$s=$db->prepare("INSERT INTO appointments(clinic_id,client_id,practitioner_id,service_id,location_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,source,status,created_by)VALUES(1,?,1,1,1,'2026-10-01 14:00:00','2026-10-01 15:00:00','2026-10-01 14:00:00','2026-10-01 15:00:00','reception','confirmed',1)");$s->execute([$client]);};
$addAppointment($newClient);$addAppointment(2);
$assert(count($c->appointments($a,$cid)['items'])===1);$assert(count($c->appointments($b,$cid)['items'])===1);
$db->exec("UPDATE users SET status='inactive' WHERE id=2");$reject(fn()=>$c->profile($b,$cid),403);
$db->exec("UPDATE customer_sessions SET last_activity_at=UTC_TIMESTAMP()-INTERVAL 30 MINUTE WHERE token_hash='".hash('sha256',$as['session_token'])."'");
$reject(fn()=>$c->session($ac,$as['session_token'],true),401);
$c->logout($ds['session_token'],$cid);$reject(fn()=>$c->session($dc,$ds['session_token']),401);
$c->rate('test','key',1,600);$reject(fn()=>$c->rate('test','key',1,600),429);
$audit=json_encode($db->query('SELECT metadata FROM audit_logs')->fetchAll());
$assert(!str_contains($audit,$invite['token'])&&!str_contains($audit,'example.test')&&!str_contains($audit,'Test Street'));
// Concurrent requests are separate processes/connections; hold the clinic lock so both queue.
$race=function(array $jobs)use($db,$name,$port){
 $db->beginTransaction();$db->query('SELECT id FROM clinics WHERE id=1 FOR UPDATE')->fetch();$workers=[];
 foreach($jobs as $job){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/onboarding-race-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('Cannot start race worker');
  fwrite($pipes[0],json_encode($job+['database'=>$name,'port'=>$port]));fclose($pipes[0]);$workers[]=[$process,$pipes];
 }
 usleep(250000);$db->commit();$results=[];
 foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Race worker failed: '.$error);$results[]=json_decode($output,true,32,JSON_THROW_ON_ERROR);}
 return $results;
};
[$r1]=$make('race-1');[$r2]=$make('race-2');
$raceBody=array_replace($body,['email'=>'race@example.test']);
$results=$race([['action'=>'register','identity'=>$r1,'body'=>$raceBody],['action'=>'register','identity'=>$r1,'body'=>$raceBody]]);
$assert($results[0]['ok']&&$results[1]['ok']);
$assert((int)$db->query("SELECT COUNT(*) FROM users WHERE email='race@example.test'")->fetchColumn()===1);
$db->exec("INSERT INTO users(id,clinic_id,email,display_name,user_type,status)VALUES(20,1,'raceinvite@example.test','Race invite','client','active')");
$old=$c->invite($staff,20,$cid);$replacement=$c->invite($staff,20,$cid);
$reject(fn()=>$c->accept($r2,['token'=>$old['token'],'claimant_name'=>'Test'],$cid),409);
[$r3]=$make('race-3');
$results=$race([['action'=>'accept','identity'=>$r2,'body'=>['token'=>$replacement['token'],'claimant_name'=>'One']],['action'=>'accept','identity'=>$r3,'body'=>['token'=>$replacement['token'],'claimant_name'=>'Two']]]);
$assert(count(array_filter($results,fn($r)=>$r['ok']))===1,'Concurrent accept granted two claims');
$pending=$c->status($r2)['onboarding_status']==='pending_review'?$r2:$r3;
$db->exec('UPDATE client_link_invitations SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id='.(int)$replacement['id']);
$reject(fn()=>$c->review($staff,20,$replacement['id'],['action'=>'approve','identity_verified'=>true,'review_code'=>$c->status($pending)['review_code']],$cid),409);
$c->review($staff,20,$replacement['id'],['action'=>'reject'],$cid);
$assert($c->status($pending)['onboarding_status']==='not_linked');
$fresh=$c->invite($staff,20,$cid);$c->accept($pending,['token'=>$fresh['token'],'claimant_name'=>'Verified test'],$cid);
$job=['action'=>'approve','client'=>20,'invitation'=>$fresh['id'],'body'=>['action'=>'approve','identity_verified'=>true,'review_code'=>$c->status($pending)['review_code']]];
$results=$race([$job,$job]);$assert(count(array_filter($results,fn($r)=>$r['ok']))===1,'Concurrent approval did not remain single-use');
$assert($c->status($pending)['onboarding_status']==='linked');
$db->exec("UPDATE customer_sessions SET last_activity_at=UTC_TIMESTAMP()-INTERVAL 10 MINUTE WHERE token_hash='".hash('sha256',$bs['session_token'])."'");
$first=$c->session($bc,$bs['session_token']);$second=$c->session($bc,$bs['session_token']);
$assert($first['idle_expires_at']===$second['idle_expires_at'],'Background read extended idle session');
$assert($c->session($bc,$bs['session_token'],true)['idle_expires_at']>$first['idle_expires_at']);
$db->exec("UPDATE customer_sessions SET expires_at=UTC_TIMESTAMP() WHERE token_hash='".hash('sha256',$bs['session_token'])."'");
$reject(fn()=>$c->session($bc,$bs['session_token'],true),401);
echo "$checks database integration checks passed on ".$db->getAttribute(PDO::ATTR_SERVER_VERSION)."; scratch database: $name\n";
