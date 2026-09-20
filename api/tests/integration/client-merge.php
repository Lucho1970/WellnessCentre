<?php
declare(strict_types=1);
// Creates synthetic data only in a brand-new random database on localhost.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AuditLogger;
use Wellness\Service\ClientService;

$port=(int)(getenv('ONBOARDING_TEST_PORT')?:13317);
$root=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('ONBOARDING_TEST_USER')?:'root',getenv('ONBOARDING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_client_merge_test_'.bin2hex(random_bytes(6));
$root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$root->exec("USE `$name`");
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$schema=preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema);$root->exec($schema);
$root->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/005_customer_onboarding.sql'));
$migration=file_get_contents(dirname(__DIR__,2).'/database/migrations/006_client_merge.sql');$root->exec($migration);
$root->exec("INSERT INTO clinics(id,name)VALUES(1,'Synthetic clinic');
INSERT INTO users(id,clinic_id,email,display_name,given_name,family_name,user_type,status)VALUES
(1,1,'admin@example.test','Admin','Admin','User','staff','active'),
(2,1,'survivor@example.test','Same Client','Same','Client','client','active'),
(3,1,'duplicate@example.test','Same Client','Same','Client','client','active'),
(4,1,'conflict@example.test','Conflict Client','Conflict','Client','client','active');
INSERT INTO client_profiles(user_id,phone,date_of_birth,administrative_notes)VALUES(2,'555-1000','1990-01-01','survivor note'),(3,'555-2000','1990-01-01','duplicate note'),(4,'555-3000','1991-01-01','conflict note');
INSERT INTO client_contact_addresses(client_id,address_json)VALUES(2,JSON_OBJECT('city','Survivor City')),(3,JSON_OBJECT('city','Duplicate City'));
INSERT INTO locations(id,clinic_id,name)VALUES(1,1,'Test location');
INSERT INTO practitioners(id,user_id,discipline)VALUES(1,1,'Therapist');
INSERT INTO services(id,clinic_id,name,price_cents)VALUES(1,1,'Massage',10000);
INSERT INTO appointments(id,clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by)VALUES(1,1,1,3,1,1,'2026-10-01 14:00:00','2026-10-01 15:00:00','2026-10-01 14:00:00','2026-10-01 15:00:00','confirmed','reception',1);
INSERT INTO consent_records(client_id,purpose_code,policy_version,status,recorded_at)VALUES(3,'care','1','granted',UTC_TIMESTAMP());
INSERT INTO customer_identities(id,identity_hash,issuer,subject,created_at)VALUES(1,REPEAT('a',64),'issuer','one',UTC_TIMESTAMP()),(2,REPEAT('b',64),'issuer','two',UTC_TIMESTAMP());
INSERT INTO customer_client_links(identity_id,client_id,clinic_id,created_at)VALUES(1,3,1,UTC_TIMESTAMP());");
$root->exec($migration);$root->exec($migration); // Backfill and rerun safety.
$config=new Config('test',false,'test-key',[],'127.0.0.1',$port,$name,'root','','staff','staff-api','scope',3600);
$database=new Database($config);$service=new ClientService($database,new AuditLogger($database));
$admin=new AuthContext(1,1,'staff','admin@example.test','Admin','staff',['super_admin']);$cid='11111111-1111-4111-8111-111111111111';
$checks=0;$assert=function(bool $ok,string $message='Assertion failed')use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=function(callable $fn,string $code)use($assert){try{$fn();}catch(ApiException $e){$assert($e->errorCode===$code,"Unexpected {$e->errorCode}");return;}throw new RuntimeException('Request should have failed');};
$preview=$service->mergePreview($admin,2,3,$cid);$assert(!$preview['blocked']);$assert($preview['relationship_counts']['appointments']===1);
$result=$service->merge($admin,2,3,['survivor_revision'=>$preview['survivor']['revision'],'duplicate_revision'=>$preview['duplicate']['revision'],'primary_email_source'=>'survivor','profile_source'=>'duplicate','address_source'=>'duplicate','reason'=>'Duplicate created during retry','confirmation'=>'MERGE 3 INTO 2'],$cid);
$assert($result['merged_client_id']===3);
$assert((int)$root->query('SELECT client_id FROM appointments WHERE id=1')->fetchColumn()===2);
$assert((int)$root->query('SELECT client_id FROM customer_client_links WHERE identity_id=1')->fetchColumn()===2);
$assert((int)$root->query('SELECT COUNT(*) FROM client_email_addresses WHERE client_id=2')->fetchColumn()===2);
$assert($root->query('SELECT status FROM users WHERE id=3')->fetchColumn()==='inactive');
$assert(str_starts_with((string)$root->query('SELECT email FROM users WHERE id=3')->fetchColumn(),'merged-client-3@'));
$directory=$service->search($admin,[]);$directoryIds=array_map('intval',array_column($directory['items'],'id'));sort($directoryIds);$assert($directoryIds===[2,4],'Merged client remained in the directory');
$activeDirectory=$service->search($admin,['status'=>'active']);$activeDirectoryIds=array_map('intval',array_column($activeDirectory['items'],'id'));sort($activeDirectoryIds);$assert($activeDirectoryIds===[2,4],'Merged client remained in the active directory');
$assert($root->query('SELECT phone FROM client_profiles WHERE user_id=2')->fetchColumn()==='555-2000');
$assert(json_decode((string)$root->query('SELECT address_json FROM client_contact_addresses WHERE client_id=2')->fetchColumn(),true)['city']==='Duplicate City');
$assert((int)$root->query("SELECT COUNT(*) FROM audit_logs WHERE action='client.merge'")->fetchColumn()===1);
$reject(fn()=>$service->merge($admin,2,3,['survivor_revision'=>'stale','duplicate_revision'=>'stale','reason'=>'Retry merge','confirmation'=>'MERGE 3 INTO 2'],'x'),'client_changed');
$body=['given_name'=>'Same','family_name'=>'Client','email'=>'third@example.test'];
try{$service->save($admin,$body,$cid);throw new RuntimeException('Possible duplicate should have been rejected');}catch(ApiException $e){$assert($e->errorCode==='possible_duplicate');$assert(array_map('intval',array_column($e->fields['candidates']??[],'id'))===[2],'Merged client appeared in duplicate candidates');}
$created=$service->save($admin,$body+['confirm_possible_duplicate'=>true],$cid);$assert($created['email']==='third@example.test');
$root->exec("INSERT INTO customer_client_links(identity_id,client_id,clinic_id,created_at)VALUES(2,4,1,UTC_TIMESTAMP())");
$conflict=$service->mergePreview($admin,2,4,$cid);$assert($conflict['blocked']);
$reject(fn()=>$service->merge($admin,2,4,['survivor_revision'=>$conflict['survivor']['revision'],'duplicate_revision'=>$conflict['duplicate']['revision'],'profile_source'=>'survivor','address_source'=>'survivor','reason'=>'Conflicting identity test','confirmation'=>'MERGE 4 INTO 2'],$cid),'identity_conflict');
$reception=new AuthContext(1,1,'staff','admin@example.test','Admin','staff',['reception']);$reject(fn()=>$service->mergePreview($reception,2,4,$cid),'forbidden');
echo "$checks client merge integration checks passed on ".$root->getAttribute(PDO::ATTR_SERVER_VERSION)."; scratch database: $name\n";
