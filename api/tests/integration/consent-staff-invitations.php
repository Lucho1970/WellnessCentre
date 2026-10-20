<?php
declare(strict_types=1);
require __DIR__.'/access-audit.php';
use Wellness\{Config,ClinicContext};
use Wellness\Auth\{VerifiedIdentity,StaffMembershipResolver};
use Wellness\Service\{StaffInvitationService,ClientConsentService,ConsentLock};
use Wellness\Http\ApiException;
$checks=0;
$invitationConfig=new Config('test',false,'test',[],'localhost',3306,'','','','','','',300,staffInvitationsEnabled:true,clinicHostMap:['audit-a.test'=>1,'audit-b.test'=>2]);
$invitations=new StaffInvitationService($database,$invitationConfig,$audit);$policy=new ClientConsentService($database,$audit);
foreach([0,1,2] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'invitation-policy');
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','invite-admin');
    INSERT INTO locations(id,clinic_id,name) VALUES(2,2,'Foreign location');");
$details=['recipient_email'=>'new-invite@example.test','given_name'=>'Synthetic','family_name'=>'Invitee','discipline'=>'Massage','location_id'=>1];
$approval=['recipient_verified'=>true,'verification_code'=>'ABCDEF123456'];
$snapshot=fn()=>[$pdo->query('SELECT * FROM staff_invitations ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM staff_invitation_claims ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM staff_memberships ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM product_identities ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM practitioners ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM user_roles ORDER BY user_id,role_id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$patients=fn()=>[$pdo->query("SELECT * FROM users WHERE user_type='client' ORDER BY id")->fetchAll(),$pdo->query('SELECT * FROM appointments ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_consent_directives ORDER BY id')->fetchAll()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot):void{$before=$snapshot();try{$work();throw new RuntimeException('Expected invitation refusal');}catch(ApiException $e){$expect($e->status===$status,'Invitation authorization status '.$status.' got '.$e->status);}$expect($snapshot()===$before,'Refusal preserves invitations/claims/memberships/identities/accounts/audits');};
$ops=fn($a)=>[fn()=>$invitations->list($a),fn()=>$invitations->create($a,$details,'deny'),fn()=>$invitations->approve($a,99999,$approval,'deny'),fn()=>$invitations->revoke($a,99999,'deny')];
foreach([$actor(2),$actor(3,['clinic_admin'],['review_privacy_audit']),$actor(3,['reception']),$actor(3,['accountant']),$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin']),$actor(3,['super_admin'])] as $bad)foreach($ops($bad) as $op)$deny($op);
$pdo->exec("UPDATE users SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=1;UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
$env['ACCESS_AUDIT_TEST_STAFF_INVITATIONS']='synthetic-local-only';
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-invitations-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-invitations-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start invitation API');fclose($pipes[0]);$path='/api/v1/admin/staff-invitations';
$write=fn($method,$body=null,$suffix='',$token=null)=>$call($path.$suffix,$token??$adminToken,$method,$body);
$claim=function(array $invite,string $subject)use($invitations):array{$identity=new VerifiedIdentity('entra-external-staff','https://synthetic-invite.test',$subject,'synthetic-tenant');$result=$invitations->claim(1,$identity,['token'=>$invite['token'],'claimant_name'=>'Synthetic claimant'],'synthetic-claim');return [$identity,['recipient_verified'=>true,'verification_code'=>$result['verification_code']]];};
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $beforePatients=$patients();
    [$status,$data,$cid]=$write('POST',$details+['clinic_id'=>2,'permissions'=>['review_privacy_audit']]);$expect($status===200||$status===201,'Authorized invitation creation survives unrelated/global restrictions');$invite=$data['data'];$id=(int)$invite['id'];
    $q=$pdo->prepare('SELECT clinic_id,token_hash FROM staff_invitations WHERE id=?');$q->execute([$id]);$stored=$q->fetch();$expect((int)$stored['clinic_id']===1&&$stored['token_hash']===hash('sha256',$invite['token']),'Clinic override ignored; only token hash stored');
    $expect(!str_contains(json_encode($event($cid)),$invite['token'])&&!str_contains(json_encode($event($cid)),'@example.test'),'Access audit excludes token and recipient contacts');
    [$identity,$valid]=$claim($invite,'new-identity');
    $deny(fn()=>$invitations->claim(1,new VerifiedIdentity('customer','https://synthetic.test','client','tenant'),['token'=>$invite['token'],'claimant_name'=>'Synthetic'],'customer-claim'));
    $deny(fn()=>$invitations->claim(1,new VerifiedIdentity('entra-external-staff','https://synthetic-invite.test','other-subject','synthetic-tenant'),['token'=>$invite['token'],'claimant_name'=>'Synthetic'],'other-claim'),409);
    $resolver=new StaffMembershipResolver($database);$clinic=ClinicContext::forHost($invitationConfig,'audit-a.test');
    $deny(fn()=>$resolver->resolve($identity,$clinic));
    [$status,$data,$cid]=$write('GET');$expect($status===200&&count($data['data']['items'])===1,'Current Super Admin can review pending signed-in claims under patient restrictions');
    $expect(json_decode($event($cid)[0]['metadata'],true)['resources'][0]===['type'=>'staff_invitation','id'=>$id],'List audit uses typed invitation reference');
    $expect(!str_contains(json_encode($event($cid)),$valid['verification_code']),'Claim verification code never copied into central audit');
    foreach([$practitionerToken,$staffToken('invite-admin','Wellness.ClinicAdmin')] as $token)foreach([['GET',null,''],['POST',$details,''],['POST',$valid,"/$id/approve"],['POST',[],"/$id/revoke"]] as [$method,$body,$suffix]){[$status,$data,$cid]=$write($method,$body,$suffix,$token);$expect($status===403&&$event($cid)[0]['outcome']==='denied','All management routes refuse other roles and centrally audit denial');}
    [$status]=$call($path,$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host cannot switch invitation authority');
    $deny(fn()=>$invitations->create($operator,$details+['role'=>'super_admin'],'role'),422);
    $deny(fn()=>$invitations->create($operator,array_replace($details,['existing_user_id'=>101]),'patient'),404);
    $deny(fn()=>$invitations->create($operator,array_replace($details,['existing_user_id'=>5]),'foreign'),404);
    $deny(fn()=>$invitations->create($operator,array_replace($details,['location_id'=>2]),'foreign-location'),404);
    $deny(fn()=>$invitations->approve($actor(5,['super_admin'],[],'staff',2),$id,$valid,'foreign'),404);
    $deny(fn()=>$invitations->revoke($actor(5,['super_admin'],[],'staff',2),$id,'foreign'),404);
    foreach([[],['recipient_verified'=>true,'verification_code'=>'wrong']] as $body)$deny(fn()=>$invitations->approve($operator,$id,$body,'verification'),422);
    $pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='super_admin';UPDATE users SET status='inactive' WHERE id=1");
    $deny(fn()=>$invitations->approve($actor(3,['super_admin']),$id,$valid,'inviter'),409);$pdo->exec("UPDATE users SET status='active' WHERE id=1");
    // Approval is atomic with all identity, role and membership changes.
    $pdo->exec("CREATE TRIGGER invitation_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    foreach([fn()=>$invitations->approve($operator,$id,$valid,'rollback'),fn()=>$invitations->revoke($operator,$id,'rollback'),fn()=>$invitations->create($operator,array_replace($details,['recipient_email'=>'rollback@example.test']),'rollback')] as $op){$before=$snapshot();try{$op();throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$before,'Business audit failure rolls back the complete invitation operation');}
    $pdo->exec('DROP TRIGGER invitation_audit_failure');
    [$status,$data,$cid]=$write('POST',$valid,"/$id/approve");$expect($status===200,'Verified claim can be approved despite unrelated restrictions');$user=(int)$data['data']['user_id'];
    $resolved=$resolver->resolve($identity,$clinic);$expect($resolved->userId===$user&&$resolved->roles===['practitioner']&&$resolved->permissions===[],'New identity receives only local practitioner access');
    $deny(fn()=>$resolver->resolve($identity,ClinicContext::forHost($invitationConfig,'audit-b.test')));
    $deny(fn()=>$invitations->approve($operator,$id,$valid,'retry'),409);$deny(fn()=>$invitations->revoke($operator,$id,'accepted'),409);
    // An explicit existing practitioner binding must retain the original local patient boundaries.
    $legacy=$invitations->create($operator,array_replace($details,['recipient_email'=>'practitioner@example.test','existing_user_id'=>2]),'legacy');[$legacyIdentity,$legacyApproval]=$claim($legacy,'existing-identity');
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=2");$deny(fn()=>$invitations->approve($operator,(int)$legacy['id'],$legacyApproval,'inactive-existing'),409);$pdo->exec("UPDATE users SET status='active' WHERE id=2");
    $pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 2,id FROM roles WHERE code='clinic_admin'");$deny(fn()=>$invitations->approve($operator,(int)$legacy['id'],$legacyApproval,'elevated-existing'),409);$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=2 AND r.code='clinic_admin'");
    $existing=$invitations->approve($operator,(int)$legacy['id'],$legacyApproval,'legacy');$resolved=$resolver->resolve($legacyIdentity,$clinic);$expect($resolved->userId===2&&(int)$existing['user_id']===2,'Reviewed existing binding retains the same local user ID');
    $deny(fn()=>$bookings->bookingClientAddress($resolved,101,'invited-restricted'),404);
    [$status,$data,$cid]=$write('GET');$expect(in_array(['type'=>'user','id'=>2],json_decode($event($cid)[0]['metadata'],true)['resources'],true),'Existing staff references in invitation history are centrally audited');
    $expect($patients()===$beforePatients,'Invitation approval never changes patients, bookings or named/global restrictions');
    $policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>false,'version'=>1],'test-global-restore');
    $deny(fn()=>$bookings->bookingClientAddress($resolved,101,'invited-named-restricted'),404);
    $policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>true,'version'=>2],'test-global-restrict');
    $pdo->exec('UPDATE staff_memberships SET status=\'revoked\' WHERE local_user_id=2');$deny(fn()=>$resolver->resolve($legacyIdentity,$clinic));$deny(fn()=>$invitations->create($operator,array_replace($details,['existing_user_id'=>2]),'revoked-membership'),409);
    $revocable=$invitations->create($operator,array_replace($details,['recipient_email'=>'revoked@example.test']),'revocable');[$revokedIdentity,$revokeApproval]=$claim($revocable,'revoked-identity');
    [$status]=$write('POST',[],"/{$revocable['id']}/revoke");$expect($status===200,'Invitation revocation remains available under global patient restriction');
    $deny(fn()=>$invitations->approve($operator,(int)$revocable['id'],$revokeApproval,'revoked'),409);
    $deny(fn()=>$invitations->claim(1,$revokedIdentity,['token'=>$revocable['token'],'claimant_name'=>'Synthetic'],'revoked'),409);
    // A malformed old reference must not disclose a client/foreign staff ID in the staff directory.
    $bad=$invitations->create($operator,array_replace($details,['recipient_email'=>'malformed@example.test']),'malformed');$pdo->prepare('UPDATE staff_invitations SET existing_user_id=101 WHERE id=?')->execute([$bad['id']]);
    [$status,$data]=$write('GET');$expect($status===200&&!in_array((int)$bad['id'],array_map('intval',array_column($data['data']['items'],'id')),true),'Legacy patient-backed invitation omitted before pagination');
    $invitations->revoke($operator,(int)$bad['id'],'repair-revoke');
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);try{$deny(fn()=>$invitations->revoke($operator,(int)$bad['id'],'busy'),503);}finally{$blocking->release();}
    $disabled=new StaffInvitationService($database,new Config('test',false,'test',[],'localhost',3306,'','','','','','',300),$audit);
    foreach([fn()=>$disabled->list($operator),fn()=>$disabled->create($operator,$details,'disabled'),fn()=>$disabled->approve($operator,$id,$valid,'disabled'),fn()=>$disabled->revoke($operator,$id,'disabled')] as $op)$deny($op,503);
    $expired=$invitations->create($operator,array_replace($details,['recipient_email'=>'expired@example.test']),'expired');$pdo->prepare('UPDATE staff_invitations SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$expired['id']]);
    $deny(fn()=>$invitations->claim(1,$identity,['token'=>$expired['token'],'claimant_name'=>'Synthetic'],'expired'),409);
    $pdo->beginTransaction();try{try{$invitations->list($operator);throw new RuntimeException('Nested operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Caller transaction preserved on refusal');}}finally{$pdo->rollBack();}
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized staff invitations: $checks checks passed; local synthetic SQL/HTTP only.\n";
