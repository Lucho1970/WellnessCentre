<?php
declare(strict_types=1);
// Disposable local SQL/HTTP; no .env, hosted patient records or provider calls.
require __DIR__.'/access-audit.php';
use Wellness\Service\{PractitionerQualificationService,ClientConsentService,ConsentLock};
use Wellness\Http\ApiException;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/040_application_administration.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/042_global_practitioners.sql'));
$checks=0;$credentials=new PractitionerQualificationService($database,$audit);$policy=new ClientConsentService($database,$audit);
foreach([0,1,2,3] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'qualification-policy');
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 4,id FROM roles WHERE code='practitioner';
    INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','qualification-admin');");
$clinicAdmin=$actor(3,['clinic_admin']);$practitioner=$actor(2);
$type=$credentials->createType($operator,['name'=>'Synthetic registration','requires_expiry'=>true],'qualification-type');$typeId=(int)$type['id'];
$body=['qualification_type_id'=>$typeId,'issuer'=>'Private synthetic credential issuer','issued_on'=>'2026-01-01','expires_on'=>'2099-01-01'];
$decision=['status'=>'verified'];
$snapshot=fn()=>[$pdo->query('SELECT * FROM qualification_types ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM practitioner_qualifications ORDER BY id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$patients=fn()=>[$pdo->query('SELECT * FROM appointments ORDER BY id')->fetchAll(),$pdo->query("SELECT * FROM users WHERE user_type='client' ORDER BY id")->fetchAll(),$pdo->query('SELECT * FROM client_consent_directives ORDER BY id')->fetchAll()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot):void{$before=$snapshot();try{$work();throw new RuntimeException('Expected credential refusal');}catch(ApiException $e){$expect($e->status===$status,'Credential refusal '.$status.' got '.$e->status);}$expect($snapshot()===$before,'Denied credential operation preserves types/records/business audits');};
$ops=fn($a)=>[fn()=>$credentials->types($a),fn()=>$credentials->list($a,2),fn()=>$credentials->createType($a,['name'=>'Denied type','requires_expiry'=>false],'denied'),fn()=>$credentials->submit($a,$body,'denied',2),fn()=>$credentials->review($a,99999,$decision,'denied')];
foreach([$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin'])] as $bad)foreach($ops($bad) as $op)$deny($op);
foreach([$actor(2),$actor(3,['reception']),$actor(3,['accountant'])] as $bad)foreach(array_slice($ops($bad),1) as $op)$deny($op);
$pdo->exec("UPDATE users SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=1;UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
$deny(fn()=>$credentials->list($actor(4)),404);$deny(fn()=>$credentials->submit($actor(4),$body,'inactive-profile'),404);
$deny(fn()=>$credentials->list($operator)); // An administrator without practitioner role has no implicit own profile.
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/qualifications-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/qualifications-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start credential API');fclose($pipes[0]);$adminToken3=$staffToken('qualification-admin','Wellness.ClinicAdmin');
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $beforePatients=$patients();
    [$status,$data]=$call('/api/v1/qualifications/types',$practitionerToken);$expect($status===200&&count($data['data'])===1,'Types readable under unrelated/global patient restrictions');
    [$status,$data,$cid]=$call('/api/v1/profile/qualifications',$practitionerToken,'POST',$body+['clinic_id'=>2,'practitioner_id'=>5,'status'=>'verified']);$expect($status===201||$status===200,'Own credential submission remains available');$id=(int)$data['data']['id'];
    $q=$pdo->prepare('SELECT clinic_id,practitioner_id,status FROM practitioner_qualifications WHERE id=?');$q->execute([$id]);$saved=$q->fetch();$expect((int)$saved['clinic_id']===1&&(int)$saved['practitioner_id']===2&&$saved['status']==='pending','Body cannot change credential owner, clinic or review decision');
    [$status,$data,$cid]=$call('/api/v1/admin/practitioners/2/qualifications',$adminToken);$expect($status===200&&count($data['data'])===1,'Admin can review staff credential data despite unrelated restrictions');
    $expect(!str_contains(json_encode($event($cid)),'Private synthetic')&&in_array(['type'=>'qualification','id'=>$id],json_decode($event($cid)[0]['metadata'],true)['resources'],true),'Audit identifies credential without copying private issuer or notes');
    foreach([['/api/v1/admin/practitioners/2/qualifications','GET',null],['/api/v1/admin/practitioners/2/qualifications','POST',$body],['/api/v1/admin/qualifications/types','POST',['name'=>'Unauthorized','requires_expiry'=>false]],["/api/v1/admin/qualifications/$id/review",'PATCH',$decision]] as [$path,$method,$payload]){[$status,$data,$cid]=$call($path,$practitionerToken,$method,$payload);$expect($status===403&&$event($cid)[0]['outcome']==='denied','Every admin credential route denies practitioners and logs denial');}
    [$status]=$call('/api/v1/admin/practitioners/2/qualifications',$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host cannot transfer credential authority');
    $deny(fn()=>$credentials->list($operator,5),404);$deny(fn()=>$credentials->submit($operator,$body,'foreign',5),404);
    $foreignType=$credentials->createType($actor(5,['super_admin'],[],'staff',2),['name'=>'Foreign type','requires_expiry'=>false],'foreign');$deny(fn()=>$credentials->submit($practitioner,array_replace($body,['qualification_type_id'=>$foreignType['id']]),'foreign-type'),404);
    [$status]=$call("/api/v1/admin/qualifications/$id/review",$adminToken3,'PATCH',$decision);$expect($status===200,'Independent Clinic Admin verifies an eligible record');
    [$status]=$call("/api/v1/admin/qualifications/$id/review",$adminToken,'PATCH',['status'=>'revoked','review_note'=>'Synthetic revocation']);$expect($status===200,'Credential revocation remains available under global patient restriction');
    $deny(fn()=>$credentials->review($operator,$id,$decision,'stale'),409);
    $owned=$credentials->submit($operator,$body,'on-behalf',3);$deny(fn()=>$credentials->review($clinicAdmin,(int)$owned['id'],$decision,'self-owner'));
    $submitted=$credentials->submit($clinicAdmin,$body,'admin-upload',2);$deny(fn()=>$credentials->review($clinicAdmin,(int)$submitted['id'],$decision,'self-upload'));
    $credentials->review($operator,(int)$submitted['id'],$decision,'independent-review');$expect(true,'Separate administrator can verify another administrator submission');
    $expired=$credentials->submit($practitioner,array_replace($body,['expires_on'=>'2026-01-02']),'expired');$deny(fn()=>$credentials->review($operator,(int)$expired['id'],$decision,'expired'),422);
    $pending=$credentials->submit($practitioner,$body,'inactive-type');$pdo->exec("UPDATE qualification_types SET active=0 WHERE id=$typeId");$deny(fn()=>$credentials->review($operator,(int)$pending['id'],$decision,'inactive-type'),422);
    $credentials->review($operator,(int)$pending['id'],['status'=>'rejected','review_note'=>'Type no longer accepted'],'reject-inactive');$expect(true,'Rejecting stale credential type remains possible');$pdo->exec("UPDATE qualification_types SET active=1 WHERE id=$typeId");
    $malformed=$credentials->submit($practitioner,$body,'missing-expiry');$bad=(int)$malformed['id'];$pdo->exec("UPDATE practitioner_qualifications SET expires_on=NULL WHERE id=$bad");$deny(fn()=>$credentials->review($operator,$bad,$decision,'missing-expiry'),422);
    $pdo->exec("UPDATE practitioner_qualifications SET submitted_by=101 WHERE id=$bad");$deny(fn()=>$credentials->review($operator,$bad,$decision,'patient-author'),404);$expect(!in_array($bad,array_map('intval',array_column($credentials->list($operator,2),'id')),true),'Patient-backed malformed provenance omitted from credential history');
    $pdo->exec("INSERT INTO practitioners(user_id,discipline) VALUES(109,'Malformed practitioner')");$patientProfile=(int)$pdo->lastInsertId();$deny(fn()=>$credentials->list($operator,$patientProfile),404);$deny(fn()=>$credentials->submit($operator,$body,'patient-profile',$patientProfile),404);
    $rollbackRecord=$credentials->submit($practitioner,$body,'rollback-fixture');
    $pdo->exec("CREATE TRIGGER qualification_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit unavailable'");
    foreach([fn()=>$credentials->submit($practitioner,$body,'rollback'),fn()=>$credentials->createType($operator,['name'=>'Rollback type','requires_expiry'=>false],'rollback'),fn()=>$credentials->review($operator,(int)$rollbackRecord['id'],$decision,'rollback')] as $op){$before=$snapshot();try{$op();throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$before,'Audit failure rolls back complete credential/type/review change');}$pdo->exec('DROP TRIGGER qualification_audit_failure');
    $expect($patients()===$beforePatients,'Credential management never changes patient records, appointments or consent instructions');
    $deny(fn()=>$credentials->createType($clinicAdmin,['name'=>'Synthetic registration','requires_expiry'=>false],'duplicate'),409);
    $pdo->exec("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce','synthetic-qualifications','synthetic-practitioner')");$identityId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,'Synthetic registry','registry@example.test')")->execute([$identityId]);$globalId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,1,2)')->execute([$globalId]);
    $globalCredentials=new PractitionerQualificationService($database,$audit,true);
    $deny(fn()=>$globalCredentials->submit($practitioner,$body,'global-own'));
    $deny(fn()=>$globalCredentials->submit($operator,$body,'global-on-behalf',2));
    $expect(count($globalCredentials->list($operator,2))>0,'Clinic can still review explicitly shared/previous local credentials when central ownership is enabled');
    $globalCredentials->review($clinicAdmin,(int)$rollbackRecord['id'],$decision,'central-independent-review');
    $globalCredentials->review($operator,(int)$rollbackRecord['id'],['status'=>'revoked','review_note'=>'Synthetic shared revocation'],'central-revoke');$expect(true,'Clinic verification and revocation remain independent of credential ownership');
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);try{$deny(fn()=>$credentials->review($operator,(int)$owned['id'],$decision,'busy'),503);}finally{$blocking->release();}
    $pdo->beginTransaction();try{try{$credentials->types($operator);throw new RuntimeException('Nested operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Caller transaction remains intact after refusal');}}finally{$pdo->rollBack();}
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized qualifications: $checks checks passed; local synthetic SQL/HTTP only.\n";
