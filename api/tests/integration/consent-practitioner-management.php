<?php
declare(strict_types=1);
// Disposable localhost SQL and synthetic signed HTTP identities only.
require __DIR__.'/access-audit.php';
use Wellness\Service\{AdminService,ClientConsentService,ConsentLock};
use Wellness\Http\ApiException;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/040_application_administration.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/042_global_practitioners.sql'));
$checks=0;$admin=new AdminService($database,$audit);$policy=new ClientConsentService($database,$audit);
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','management-admin');
    INSERT INTO locations(id,clinic_id,name) VALUES(2,2,'Foreign private location');
    INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(2,2,1);");
$set=function(int $target,bool $restricted)use($pdo,$policy,$actor):void{$q=$pdo->prepare("SELECT version FROM client_consent_directives WHERE client_id=101 AND scope='staff_access' AND target_staff_id=?");$q->execute([$target]);$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>$restricted,'version'=>(int)$q->fetchColumn()],'management-consent');};
foreach([0,1,2,3] as $target)$set($target,true);
$update=['given_name'=>'Synthetic','family_name'=>'Practitioner','display_name'=>'Synthetic Practitioner','email'=>'updated-practitioner@example.test','location_id'=>1,'discipline'=>'Massage','credentials'=>'Synthetic credential','booking_mode'=>'practitioner_managed','status'=>'active','active'=>true];
$new=['tenant_id'=>'11111111-1111-4111-8111-111111111111','object_id'=>'22222222-2222-4222-8222-222222222222','email'=>'new-practitioner@example.test','given_name'=>'New','family_name'=>'Practitioner','display_name'=>'New Practitioner','location_id'=>1,'discipline'=>'Massage','credentials'=>'Synthetic','booking_mode'=>'practitioner_managed'];
$legacy=['user_id'=>6,'location_id'=>1,'discipline'=>'Massage'];
$snapshot=fn()=>[$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM practitioners ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM practitioner_locations ORDER BY practitioner_id,location_id')->fetchAll(),$pdo->query('SELECT * FROM identity_links ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM user_roles ORDER BY user_id,role_id')->fetchAll(),$pdo->query('SELECT * FROM staff_accounts ORDER BY user_id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$patients=fn()=>[$pdo->query("SELECT * FROM users WHERE user_type='client' ORDER BY id")->fetchAll(),$pdo->query('SELECT * FROM appointments ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_consent_directives ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_merge_records ORDER BY id')->fetchAll()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot):void{$before=$snapshot();try{$work();throw new RuntimeException('Expected administration refusal');}catch(ApiException $e){$expect($e->status===$status,'Expected administration status '.$status.' got '.$e->status);}$expect($snapshot()===$before,'Denied administration leaves all account/profile/identity/audit rows unchanged');};
$ops=fn($a)=>[fn()=>$admin->practitioners($a),fn()=>$admin->onboardPractitioner($a,$new,'denied'),fn()=>$admin->updatePractitioner($a,2,$update,'denied'),fn()=>$admin->createPractitioner($a,$legacy,'denied')];
foreach([$actor(2),$actor(3,['reception']),$actor(3,['accountant']),$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin'])] as $bad)foreach($ops($bad) as $op)$deny($op);
foreach(array_slice($ops($actor(3,['super_admin'])),0,3) as $op)$deny($op);
foreach([fn()=>$admin->practitioners($actor(3,['clinic_admin'])),fn()=>$admin->onboardPractitioner($actor(3,['clinic_admin']),$new,'denied'),fn()=>$admin->updatePractitioner($actor(3,['clinic_admin']),2,$update,'denied')] as $op)$deny($op);
$pdo->exec("UPDATE users SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=1;UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/practitioner-management-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/practitioner-management-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start synthetic administration API');fclose($pipes[0]);$path='/api/v1/admin/practitioners';
$write=fn($method,$body=null,$suffix='',$token=null)=>$call($path.$suffix,$token??$adminToken,$method,$body);
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    [$status,$data,$cid,$raw]=$write('GET');$expect($status===200,'Patient-free directory available under global restrictions');
    $expect(!str_contains($raw,'Foreign private location')&&!in_array(5,array_map('intval',array_column($data['data'],'practitioner_id')),true),'Directory excludes foreign staff and malformed cross-clinic location links');
    $expect(!str_contains($raw,'Synthetic Related')&&!str_contains(json_encode($event($cid)),'@example.test'),'Directory and central audit do not disclose patient records or copy staff contacts into audit metadata');
    foreach([$practitionerToken,$staffToken('management-admin','Wellness.ClinicAdmin')] as $token)foreach([['GET',null,''],['POST',$new,'/onboard'],['PATCH',$update,'/2']] as [$method,$body,$suffix]){[$status,$data,$cid]=$write($method,$body,$suffix,$token);$expect($status===403&&$event($cid)[0]['outcome']==='denied','Every privileged directory/onboard/update route denies and audits other roles');}
    [$status]=$call($path,$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host switch cannot transfer administration');
    $patientBefore=$patients();$identityBefore=$pdo->query('SELECT * FROM identity_links ORDER BY id')->fetchAll();
    [$status,$data,$cid]=$write('PATCH',array_replace($update,['status'=>'inactive','active'=>false,'clinic_id'=>2,'user_id'=>105,'roles'=>['super_admin'],'permissions'=>['review_privacy_audit']]),'/2');
    $expect($status===200&&!$data['data']['active'],'Practitioner can be deactivated despite restricted assigned patients');
    $expect($patients()===$patientBefore&&$pdo->query('SELECT * FROM identity_links ORDER BY id')->fetchAll()===$identityBefore,'Deactivation preserves all patients, bookings, directives, merges and identity bindings');
    [$status]=$call('/api/v1/auth/me',$practitionerToken);$expect($status===403,'Existing signed workforce token cannot use inactive local account');
    $stale=$actor(2);$deny(fn()=>$admin->updatePractitioner($stale,2,$update,'stale'));
    [$status]=$write('PATCH',$update,'/2');$expect($status===200,'Explicit reactivation remains possible without clearing consent');
    [$status,$data]=$call('/api/v1/booking-clients/101/address',$practitionerToken);$expect($status===403||$status===404,'Reactivated practitioner still cannot read restricted patient address');
    $expect($patients()===$patientBefore,'Reactivation never restores or changes patient consent');
    [$status,$data,$cid]=$write('POST',$new+['mfa_required'=>false,'clinic_id'=>2,'roles'=>['super_admin'],'permissions'=>['review_privacy_audit']],'/onboard');
    $expect($status===201||$status===200,'Explicit identity onboarding permitted under unrelated restrictions');$newUser=(int)$data['data']['user_id'];$newId=(int)$data['data']['id'];
    $q=$pdo->prepare('SELECT clinic_id,user_type FROM users WHERE id=?');$q->execute([$newUser]);$account=$q->fetch();$expect((int)$account['clinic_id']===1&&$account['user_type']==='staff','Body clinic override cannot move onboarding');
    $q=$pdo->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?');$q->execute([$newUser]);$expect($q->fetchAll(PDO::FETCH_COLUMN)===['practitioner'],'Onboarding grants only hard-coded practitioner role');
    $q=$pdo->prepare('SELECT mfa_required FROM staff_accounts WHERE user_id=?');$q->execute([$newUser]);$expect((int)$q->fetchColumn()===1,'Onboarding payload cannot disable the MFA-required account setting');
    $q=$pdo->prepare('SELECT COUNT(*) FROM user_permissions WHERE user_id=?');$q->execute([$newUser]);$expect((int)$q->fetchColumn()===0&&$patients()===$patientBefore,'No injected permissions, patient links or consent changes');
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$expect(in_array(['type'=>'practitioner','id'=>$newId],$refs,true)&&in_array(['type'=>'user','id'=>$newUser],$refs,true),'Success audit identifies new staff and profile without contacts or identity subjects');
    $deny(fn()=>$admin->onboardPractitioner($operator,array_replace($new,['email'=>'different@example.test']),'linked'),409);
    foreach([5,999999] as $id)$deny(fn()=>$admin->updatePractitioner($operator,$id,$update,'foreign'),404);
    $deny(fn()=>$admin->updatePractitioner($operator,2,array_replace($update,['location_id'=>2]),'foreign-location'),404);
    foreach(['false',0,null] as $active)$deny(fn()=>$admin->updatePractitioner($operator,2,array_replace($update,['active'=>$active]),'activation-type'),422);
    // Malformed legacy client-as-practitioner links must never open patient account maintenance.
    $pdo->exec("INSERT INTO practitioners(user_id,discipline) VALUES(109,'Malformed legacy profile')");$bad=(int)$pdo->lastInsertId();$deny(fn()=>$admin->updatePractitioner($operator,$bad,$update,'bad-type'),404);
    $expect(!in_array(109,array_map('intval',array_column($admin->practitioners($operator),'user_id')),true),'Directory omits malformed patient-backed practitioner profile');
    $deny(fn()=>$admin->createPractitioner($operator,$legacy,'inactive-target'),422);
    $pdo->exec("INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(201,1,'profile-only@example.test','Profile only','staff','active')");
    [$status,$data]=$write('POST',array_replace($legacy,['user_id'=>201,'roles'=>['super_admin']]),'',$staffToken('management-admin','Wellness.ClinicAdmin'));
    $expect($status===200||$status===201,'Existing Clinic Admin permission to create local staff profile is preserved');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM user_roles WHERE user_id=201')->fetchColumn()===0,'Profile creation alone never binds an identity or adds a login role');
    // All writes and successful business audit must roll back as one operation.
    $pdo->exec("CREATE TRIGGER management_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    foreach([fn()=>$admin->updatePractitioner($operator,2,array_replace($update,['status'=>'inactive','active'=>false]),'audit-fail'),fn()=>$admin->onboardPractitioner($operator,array_replace($new,['email'=>'rollback@example.test','object_id'=>'33333333-3333-4333-8333-333333333333']),'audit-fail')] as $op){$before=$snapshot();try{$op();throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$before,'Business audit failure rolls back identity, account, role, profile and location changes');}
    $pdo->exec('DROP TRIGGER management_audit_failure');
    $pdo->exec("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce','synthetic-registry','synthetic-practitioner');");$identity=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,'Synthetic registry','registry@example.test')")->execute([$identity]);$global=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,1,2)')->execute([$global]);
    $registeredAdmin=new AdminService($database,$audit,true);
    $deny(fn()=>$registeredAdmin->updatePractitioner($operator,2,array_replace($update,['credentials'=>'Unshared credential']),'credentials'),403);
    $registeredAdmin->updatePractitioner($operator,2,array_replace($update,['status'=>'inactive','active'=>false]),'global-deactivate');
    $expect($patients()===$patientBefore,'Central registration does not prevent local deactivation or clear patient instructions');
    $registeredAdmin->updatePractitioner($operator,2,$update,'global-reactivate');
    $pdo->exec("UPDATE global_practitioner_clinics SET status='removed' WHERE clinic_id=1 AND practitioner_id=2");
    $deny(fn()=>$registeredAdmin->updatePractitioner($operator,2,$update,'removed'),409);
    $pdo->exec("UPDATE global_practitioner_clinics SET status='active' WHERE clinic_id=1 AND practitioner_id=2");
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);try{$deny(fn()=>$admin->updatePractitioner($operator,2,$update,'busy'),503);}finally{$blocking->release();}
    $pdo->beginTransaction();try{try{$admin->updatePractitioner($operator,2,$update,'nested');throw new RuntimeException('Nested operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Nested administration refused without disturbing caller transaction');}}finally{$pdo->rollBack();}
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized practitioner management: $checks checks passed; local synthetic SQL/HTTP only.\n";
