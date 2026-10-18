<?php
declare(strict_types=1);
// Disposable localhost SQL only; never loads .env or hosted patient records.
require dirname(__DIR__,2).'/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\{AddressCoverageService,AuditLogger,BookingService,ClientOverviewService,RecurringBookingService};

if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Explicit disposable database setup is required.');
$port=(int)(getenv('RECURRING_TEST_PORT')?:13317);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('RECURRING_TEST_USER')?:'root',getenv('RECURRING_TEST_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_booking_access_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');
$offset=strpos($schema,'CREATE TABLE form_library_entries');
if($offset===false)throw new RuntimeException('Cannot isolate the booking schema baseline.');
// Use the established booking baseline and only the feature migrations needed here.
// Later unrelated fresh-schema library foreign keys are outside this authorization test.
$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',substr($schema,0,$offset)));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/005_customer_onboarding.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic A'),(2,'Synthetic B');
    INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES
    (1,1,'admin@example.test','Admin','staff','active'),(2,1,'practitioner@example.test','Practitioner','staff','active'),
    (3,1,'other@example.test','Other practitioner','staff','active'),(4,1,'disabled-profile@example.test','Disabled profile','staff','active'),
    (5,2,'foreign-staff@example.test','Foreign staff','staff','active'),(6,1,'inactive-staff@example.test','Inactive staff','staff','inactive');
    INSERT INTO practitioners(id,user_id,discipline,active) VALUES(2,2,'Massage',1),(3,3,'Massage',1),(4,4,'Massage',0),(5,5,'Massage',1),(6,6,'Massage',1);
    INSERT INTO locations(id,clinic_id,name,timezone) VALUES(1,1,'Synthetic location','America/Toronto');
    INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(1,1,'synthetic','Synthetic service',100);
    INSERT INTO service_duration_options(id,service_id,duration_minutes,price_cents) VALUES(1,1,60,100);");
$clientInsert=$pdo->prepare("INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(?,?,?,?,'client',?)");
$profileInsert=$pdo->prepare("INSERT INTO client_profiles(user_id,phone,date_of_birth) VALUES(?,?,'1990-01-01')");
$addressInsert=$pdo->prepare('INSERT INTO client_contact_addresses(client_id,address_json) VALUES(?,?)');
foreach([101=>'Appointment',102=>'Created',103=>'Form',104=>'Unrelated',105=>'Foreign',106=>'Inactive',107=>'Merged',108=>'Revoked form',109=>'No address'] as $id=>$label){
    $clientInsert->execute([$id,$id===105?2:1,"client$id@example.test","Synthetic $label",$id===106?'inactive':'active']);
    $profileInsert->execute([$id,'+1416555'.str_pad((string)$id,4,'0',STR_PAD_LEFT)]);
    if($id!==109)$addressInsert->execute([$id,json_encode(['address_line1'=>"$id Synthetic Street",'address_line2'=>'','city'=>'Toronto','province'=>'ON','postal_code'=>'M5V 1A1','country'=>'Canada'],JSON_THROW_ON_ERROR)]);
}
$appointmentInsert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(1,1,?,?,1,'2026-01-01 10:00:00','2026-01-01 11:00:00','2026-01-01 10:00:00','2026-01-01 11:00:00','completed','admin',1)");
foreach([[101,2],[104,3],[106,2],[107,2],[109,2]] as $row)$appointmentInsert->execute($row);
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','','workforce','api','scope',300);
$database=new Database($config);$audit=new AuditLogger($database);
(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$actor=fn(int $id=2,array $roles=['practitioner'],array $permissions=[],string $type='staff',int $clinic=1)=>new AuthContext($id,$clinic,'','','',$type,$roles,$permissions);
$practitioner=$actor();$operator=$actor(1,['super_admin']);
$audit->write(1,$practitioner,'synthetic-created','client.create','client',102);
$pdo->exec("INSERT INTO form_templates(id,clinic_id,owner_practitioner_id,name,form_type,definition) VALUES(1,1,2,'Synthetic form','intake','{}');
    INSERT INTO user_roles(user_id,role_id) SELECT 2,id FROM roles WHERE code='practitioner';
    INSERT INTO client_form_tasks(clinic_id,client_id,practitioner_id,form_template_id,assigned_by,status) VALUES(1,103,2,1,1,'pending'),(1,108,2,1,1,'revoked');
    INSERT INTO client_merge_records(clinic_id,survivor_client_id,duplicate_client_id,merged_by,reason,primary_email_source,profile_source,address_source,relationship_counts)
    VALUES(1,101,107,1,'Synthetic merge','survivor','survivor','survivor','{}');");
$_ENV['CLIENT_FORMS_ENABLED']='true';
$bookings=new BookingService($database,$audit,new AddressCoverageService($database,$config));
$series=new RecurringBookingService($database,$bookings);$checks=0;
$overview=new ClientOverviewService($database,$audit,$config);
$check=function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=function(callable $action,int $status,string $code)use($check){try{$action();}catch(ApiException $e){$check($e->status===$status&&$e->errorCode===$code,"Expected $status/$code, got {$e->status}/{$e->errorCode}");return;}throw new RuntimeException("Expected $code");};
$ids=function(AuthContext $who,array $query=['q'=>'Synthetic'])use($bookings):array{
    $ids=array_map('intval',array_column($bookings->bookingClients($who,$query,'synthetic-search')['items'],'id'));sort($ids);return $ids;
};
$own=[101,102,103,109];$all=[101,102,103,104,108,109];
$overviewContact=function(int $id)use($overview,$operator):bool{
    foreach($overview->practitionerAccess($operator,$id,[],'synthetic-overview')['items'] as $row)if((int)$row['user_id']===2)return $row['configured_access']['booking_contact'];
    throw new RuntimeException('Missing synthetic practitioner');
};
$check($overviewContact(103)&&!$overviewContact(104),'Access summary agrees with form-only and unrelated lookup policy');
foreach([[],['scope'=>'clinic'],['scope'=>'practitioner'],['practitioner_id'=>3],['scope'=>'admin','client_id'=>104]] as $overrides){
    $check($ids($practitioner,['q'=>'Synthetic']+$overrides)===$own,'Query hints must not broaden relationship scope');
}
$check($ids($practitioner,['date_of_birth'=>'1990-01-01'])===$own,'DOB-only searches retain scope');
$check($ids($practitioner,['q'=>'Unrelated'])===[],'Other practitioner contacts are hidden');
$check($ids($practitioner,['q'=>"%' OR 1=1 --"])===[],'Search text cannot change SQL scope');
foreach([101,102,103] as $id){
    $check($bookings->bookingClientAddress($practitioner,$id,'synthetic-address')['address']['address_line1']==="$id Synthetic Street",'Related address available');
    $bookings->assertBookingClientAccess($practitioner,$id);$checks++;
}
$check($bookings->bookingClientAddress($practitioner,109,'synthetic-address')===['address'=>null],'Authorized client without address stays distinguishable from no access');
foreach([104,105,106,107,108,99999] as $id){
    $deny(fn()=>$bookings->bookingClientAddress($practitioner,$id,'synthetic-denied'),404,'client_not_found');
    $deny(fn()=>$bookings->assertBookingClientAccess($practitioner,$id),422,'invalid_client');
}
foreach([['super_admin'],['clinic_admin'],['reception'],['reception','practitioner']] as $roles){
    $who=$actor(1,$roles);$check($ids($who)===$all,'Operators retain clinic contacts');
    $check($bookings->bookingClientAddress($who,104,'synthetic-operator')['address']!==null,'Operators retain unrelated saved addresses');
    $deny(fn()=>$bookings->bookingClientAddress($who,105,'synthetic-foreign'),404,'client_not_found');
}
$delegated=$actor(2,['practitioner'],['schedule_for_other_practitioners']);
$check($ids($delegated)===$all,'Explicit delegation permits clinic contact lookup');
$check($bookings->bookingClientAddress($delegated,104,'synthetic-delegated')['address']!==null,'Explicit delegation permits saved address lookup');
$deny(fn()=>$bookings->bookingClientAddress($delegated,105,'synthetic-foreign'),404,'client_not_found');
// Permission revocation takes effect with the next server-derived AuthContext.
$check($ids($practitioner)===$own,'No fallback to delegated scope after permission removal');
foreach([$actor(4),$actor(4,['practitioner'],['schedule_for_other_practitioners']),$actor(6),$actor(5)] as $who){
    $check($ids($who)===[],'Inactive profile/account and foreign staff cannot see contacts');
    $deny(fn()=>$bookings->bookingClientAddress($who,101,'synthetic-denied'),404,'client_not_found');
}
foreach([$actor(1,['accountant'],['schedule_for_other_practitioners']),$actor(101,['super_admin'],[],'client'),$actor(1,[],['schedule_for_other_practitioners'])] as $who){
    $deny(fn()=>$bookings->bookingClients($who,['q'=>'Synthetic']),403,'forbidden');
    $deny(fn()=>$bookings->bookingClientAddress($who,101,'synthetic-denied'),403,'forbidden');
}
$_ENV['CLIENT_FORMS_ENABLED']='false';
$check($ids($practitioner)===[101,102,109],'Disabled forms cannot grant access');
$check(!$overviewContact(103),'Access summary respects disabled forms');
$deny(fn()=>$bookings->bookingClientAddress($practitioner,103,'synthetic-disabled'),404,'client_not_found');
$_ENV['CLIENT_FORMS_ENABLED']='true';
$pdo->exec("UPDATE client_form_tasks SET status='revoked' WHERE client_id=103");
$check(!$overviewContact(103),'Access summary respects revoked form relationships');
$deny(fn()=>$bookings->bookingClientAddress($practitioner,103,'synthetic-revoked'),404,'client_not_found');
$pdo->exec("UPDATE client_form_tasks SET status='pending' WHERE client_id=103");
// Direct writes must not manufacture an appointment relationship for a guessed client ID.
$body=['client_id'=>104,'delivery_mode'=>'clinic','location_id'=>1,'practitioner_id'=>2,'service_id'=>1,'duration_option_id'=>1,'starts_at'=>'2027-01-20T10:00:00-05:00','idempotency_key'=>'synthetic-unauthorized-booking'];
$before=$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$deny(fn()=>$bookings->create($practitioner,$body,'synthetic-direct'),422,'invalid_client');
foreach([false,true] as $apply)$deny(fn()=>$series->create($practitioner,$body+['recurrence'=>['frequency'=>'weekly','count'=>2]],'synthetic-series',$apply),422,'invalid_client');
$check($pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===$before&&!$pdo->inTransaction(),'Denied writes leave no relationship or open transaction');
$check($ids($practitioner)===$own,'Denied writes cannot unlock contacts');
$events=$pdo->query("SELECT actor_user_id,correlation_id,metadata FROM audit_logs WHERE action='client.booking_contacts.search' ORDER BY id LIMIT 1")->fetch();
$check((int)$events['actor_user_id']===2&&$events['correlation_id']==='synthetic-search','Search audit is attributable');
$check(json_decode($events['metadata'],true,32,JSON_THROW_ON_ERROR)===['client_ids'=>$own],'Search audit contains only disclosed IDs, no search terms or contacts');
// Positive writer regression: established and delegated contacts remain bookable.
$_ENV['MAIL_ENABLED']='false';
$pdo->exec("UPDATE practitioners SET booking_mode='practitioner_managed' WHERE id=2;
    UPDATE services SET requires_room=0,recurrence_allowed=1,lead_time_minutes=0,booking_horizon_days=365 WHERE id=1;
    INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(2,1);
    INSERT INTO practitioner_services(practitioner_id,service_id,offers_clinic) VALUES(2,1,1);
    INSERT INTO service_locations(service_id,location_id) VALUES(1,1);");
for($day=1;$day<=7;$day++)$pdo->prepare("INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from) VALUES(2,1,?,'09:00:00','18:00:00','2020-01-01')")->execute([$day]);
$start=(new DateTimeImmutable('now',new DateTimeZone('America/Toronto')))->modify('+14 days')->setTime(10,0);
foreach([[$practitioner,101],[$delegated,104],[$operator,104]] as $index=>[$who,$clientId]){
    $request=array_replace($body,['client_id'=>$clientId,'starts_at'=>$start->modify("+$index days")->format('Y-m-d\TH:i:sP'),'idempotency_key'=>'synthetic-allowed-'.$index]);
    $saved=$bookings->create($who,$request,'synthetic-allowed');
    $check((int)$saved['client_id']===$clientId,'Authorized writer retains booking behavior');
}
$request=array_replace($body,['client_id'=>101,'starts_at'=>$start->modify('+7 days')->format('Y-m-d\TH:i:sP'),'idempotency_key'=>'synthetic-allowed-series','recurrence'=>['frequency'=>'weekly','count'=>2]]);
$preview=$series->create($practitioner,$request,'synthetic-series-preview',false);
$check($preview['ready']&&!$preview['applied'],'Related practitioner can preview a recurring booking');
echo "Booking client SQL authorization: $checks checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)."; synthetic DB $name retained.\n";
