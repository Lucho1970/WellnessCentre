<?php
declare(strict_types=1);
// Synthetic local database + real HTTP auth only. Retains booking and central-audit regressions.
require __DIR__.'/access-audit.php';
use Wellness\Service\{PrivacyAuditService,AdminService};
use Wellness\Http\ApiException;
$checks=0;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/051_privacy_audit_review.sql'));
$expect((int)$pdo->query("SELECT COUNT(*) FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE p.code='review_privacy_audit'")->fetchColumn()===0,'Migration grants nobody access');
$pdo->exec("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','reviewer'); INSERT IGNORE INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='super_admin'");
$service=new PrivacyAuditService($database,$audit);$admin=new AdminService($database,$audit);
$reviewer=$actor(1,['super_admin'],['review_privacy_audit']);
$denied=function(callable $work,int $status=403)use($expect){try{$work();throw new RuntimeException('Expected privacy authorization failure');}catch(ApiException $e){$expect($e->status===$status,'Expected denial status '.$status);}};
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/privacy-review-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/privacy-review-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start privacy test API');fclose($pipes[0]);
$grantorToken=$staffToken('reviewer','Wellness.SuperAdmin');
$update=['display_name'=>'Admin','email'=>'admin@example.test','status'=>'active','roles'=>['super_admin'],'permissions'=>['review_privacy_audit']];
$today=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->format('Y-m-d');$tomorrow=(new DateTimeImmutable('tomorrow',new DateTimeZone('UTC')))->format('Y-m-d');
$filters=['from'=>$today,'until'=>$tomorrow,'resource_type'=>'client','resource_id'=>777];$path='/api/v1/admin/privacy-audit';
$search=fn(array $query=[],?string $token=null)=>$call($path.'?'.http_build_query($query),$token??$adminToken);
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Privacy review API ready');
    foreach([$adminToken,$practitionerToken,$grantorToken] as $token){[$status,,$cid]=$search([],$token);$expect($status===403&&$event($cid)[0]['outcome']==='denied','Roles alone grant no privacy history and denial is audited');}
    [$status]=$call('/api/v1/admin/staff/1',$adminToken,'PATCH',$update);$expect($status===422,'Reviewer cannot self-grant');
    [$status]=$call('/api/v1/admin/staff/1',$grantorToken,'PATCH',$update);$expect($status===200,'Another clinic Super Admin explicitly grants privacy review');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/1',$adminToken,'PUT');$expect($status===403,'Dedicated grant endpoint cannot self-grant');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/5',$grantorToken,'PUT');$expect($status===404,'Dedicated grants cannot cross clinic boundaries');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/2',$grantorToken,'PUT');$expect($status===422,'Practitioner-only account cannot receive privacy review');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/3',$practitionerToken,'PUT');$expect($status===403,'Ordinary practitioner cannot grant audit access');
    $expect((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='staff.update' AND entity_id=1 AND metadata LIKE '%review_privacy_audit%'")->fetchColumn()>0,'Permission assignment audited');
    [$status,$me]=$call('/api/v1/auth/me',$adminToken);$expect($status===200&&in_array('review_privacy_audit',$me['data']['permissions'],true),'Current grant reaches portal permissions');
    $insert=$pdo->prepare("INSERT INTO access_audit_events(event_group,part_number,part_count,clinic_id,actor_type,actor_id,correlation_id,action,route_key,http_method,http_status,outcome,ip_address,metadata,created_at) VALUES(?,1,1,?,'staff',2,'private-correlation-secret','access.read','/api/v1/clients/:id','GET',?,?,INET6_ATON('127.0.0.1'),?,?)");
    $seed=function(?int $clinic,string $outcome='success',string $resource='client',int $id=777,?string $date=null)use($pdo,$insert,$today):int{
        $meta=['resources'=>$outcome==='success'?[['type'=>$resource,'id'=>$id,'body'=>'secret-clinical']]:[],'attempted_resources'=>$outcome==='denied'?[['type'=>$resource,'id'=>$id]]:[], 'payload'=>'Private street address','token'=>'private-token'];
        $insert->execute([bin2hex(random_bytes(16)),$clinic,$outcome==='denied'?403:200,$outcome,json_encode($meta,JSON_THROW_ON_ERROR),$date??($today.' 12:00:00')]);return (int)$pdo->lastInsertId();
    };
    $foreign=$seed(2);$global=$seed(null);$old=$seed(1,'success','client',777,'2020-01-01 00:00:00');$until=$seed(1,'success','client',777,$tomorrow.' 00:00:00');
    $pdo->beginTransaction();$ids=[];for($i=0;$i<55;$i++)$ids[]=$seed(1);$pdo->commit();$lastDenied=$seed(1,'denied');
    // Unknown injected metadata/path strings never become review payloads.
    $pdo->prepare("UPDATE access_audit_events SET route_key='/api/v1/clients/private-patient-secret',action='private-action-secret' WHERE id=?")->execute([$ids[0]]);
    [$status,$body,$cid,$raw]=$search($filters);$data=$body['data'];$shown=array_column($data['items'],'id');
    $expect($status===200&&count($shown)===50&&$shown[0]===$lastDenied,'Bounded newest-first page with denied attempts');
    $expect(!array_intersect($shown,[$foreign,$global,$old,$until]),'Other-clinic, global and outside-window audit rows excluded before limit');
    foreach(['secret-clinical','Private street address','private-token','private-correlation-secret','127.0.0.1','ip_address','metadata','private-patient-secret','private-action-secret'] as $secret)$expect(!str_contains($raw,$secret),'No unapproved audit payload field: '.$secret);
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$expect(count($refs)===50&&$refs[0]['type']==='audit_event'&&!in_array(['type'=>'client','id'=>777],$refs,true),'Audit of review records disclosed audit IDs, not nested patient references');
    $logged=$pdo->prepare("SELECT metadata FROM audit_logs WHERE correlation_id=? AND action='privacy_audit.search'");$logged->execute([$cid]);$meta=json_decode($logged->fetchColumn(),true);$expect($meta['audit_event_ids']===$shown&&$meta['filters']['resource_id']===777,'Durable search audit includes normalized filters and row IDs');
    [$status,$next]=$search($filters+['before'=>$data['next_before']]);$rest=array_column($next['data']['items'],'id');$expect($status===200&&count($rest)===6&&$next['data']['next_before']===null&&!array_intersect($rest,$shown),'Keyset pagination has no duplicates or skipped original rows');
    $last=array_values(array_filter($next['data']['items'],fn($row)=>$row['id']===$ids[0]))[0];$expect($last['route']==='/api/v1/clients/:value'&&$last['action']==='access.other','Legacy unrecognized path and action redacted');
    [$status,$denials]=$search($filters+['outcome'=>'denied']);$expect($status===200&&array_column($denials['data']['items'],'id')===[$lastDenied],'Outcome search finds attempted references');
    [$status,$actors]=$search($filters+['actor_type'=>'staff','actor_id'=>1]);$expect($status===200&&$actors['data']['items']===[],'Actor filters apply in SQL with actor namespace');
    foreach([['clinic_id'=>2],['from'=>'2026-02-30'],['actor_id'=>'1 OR 1=1'],['resource_type'=>'payload','resource_id'=>1],['from'=>'2020-01-01','until'=>'2026-01-01']] as $bad){[$status]=$search($bad);$expect($status===422,'Invalid/broadening filters rejected');}
    // Consent restriction never grants clinical access; separately authorized minimal oversight remains possible.
    $pdo->exec("INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,101,'staff_access',1,1,1)");
    [$status]=$search($filters);$expect($status===200,'Explicit oversight authority can investigate while normal patient access is restricted');
    [$status]=$call('/api/v1/clients/101',$adminToken);$expect($status===403,'Privacy reviewer permission does not bypass clinical consent');
    $pdo->exec("INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,101,'staff_access',3,1,1)");
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/1',$grantorToken,'DELETE');$expect($status===200,'Restricted grantor can revoke only the privacy capability');
    [$status]=$search($filters);$expect($status===403,'Restricted-patient oversight grant revocation takes effect');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/1',$grantorToken,'PUT');$expect($status===200,'Narrow oversight grant remains available without changing clinical authority');
    $pdo->exec('INSERT INTO staff_accounts(user_id) VALUES(101)');
    [$status,$directory]=$call('/api/v1/admin/staff',$grantorToken);$expect($status===200&&!in_array(101,array_map('intval',array_column($directory['data'],'id')),true),'Malformed client staff-account link cannot enter the patient-free staff directory');
    [$status]=$call('/api/v1/admin/staff',$practitionerToken);$expect($status===403,'Staff-directory exemption never grants practitioner access');
    $pdo->exec('DELETE FROM staff_accounts WHERE user_id=101');
    $pdo->exec('DELETE FROM client_consent_directives');
    // Revocation on the next request, including service calls holding an old principal.
    [$status]=$call('/api/v1/admin/staff/1',$grantorToken,'PATCH',array_replace($update,['permissions'=>[]]));$expect($status===200,'Grant can be revoked through audited staff administration');
    [$status]=$search($filters);$expect($status===403,'Revoked HTTP principal cannot retrieve cached history');$denied(fn()=>$service->search($reviewer,$filters,'old-principal'));
    [$status]=$call('/api/v1/admin/staff/1',$grantorToken,'PATCH',$update);$expect($status===200,'Explicit regrant succeeds');
    foreach([['reception'],['practitioner'],['accountant']] as $roles){$denied(fn()=>$service->search($actor(1,$roles,['review_privacy_audit']),[],'bad-role'));$denied(fn()=>$admin->updateStaff($actor(3,['super_admin']),1,array_replace($update,['roles'=>$roles]),'invalid-grant'),422);}
    $denied(fn()=>$service->search($actor(101,['super_admin'],['review_privacy_audit'],'client'),[],'client-review'));
    $denied(fn()=>$service->search($actor(1,['super_admin'],['review_privacy_audit'],'staff',2),[],'foreign-context'));
    $denied(fn()=>$admin->updateStaff($actor(3,['super_admin']),5,$update,'foreign-grant'),404);
    $denied(fn()=>$service->search($actor(1,['application_admin'],['review_privacy_audit']),[],'global-admin'));
    $pdo->exec("INSERT IGNORE INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin'");
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/3',$adminToken,'PUT');$expect($status===200,'Super Admin may explicitly authorize a clinic administrator');
    $clinicAdminToken=$staffToken('reviewer','Wellness.ClinicAdmin');[$status]=$search($filters,$clinicAdminToken);$expect($status===200,'Clinic Admin with explicit grant can review');
    [$status]=$call('/api/v1/admin/privacy-audit/reviewers/1',$clinicAdminToken,'DELETE');$expect($status===403,'Clinic Admin reviewer cannot administer permissions');
    $pdo->exec("INSERT IGNORE INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';INSERT INTO user_permissions(user_id,permission_id,assigned_by) SELECT 5,id,5 FROM permissions WHERE code='review_privacy_audit'");
    $foreignResult=$service->search($actor(5,['super_admin'],['review_privacy_audit'],'staff',2),$filters,'foreign-local-review');$expect(array_column($foreignResult['items'],'id')===[$foreign],'Separate clinic grant reveals only its own clinic history');
    $pdo->exec("UPDATE clinics SET status='inactive' WHERE id=1");try{$denied(fn()=>$service->search($reviewer,[],'inactive-clinic'));}finally{$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");}
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=1");try{$denied(fn()=>$service->search($reviewer,[],'inactive-reviewer'));}finally{$pdo->exec("UPDATE users SET status='active' WHERE id=1");}
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');try{$denied(fn()=>$service->search($reviewer,[],'role-removed'));}finally{$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");}
    $pdo->beginTransaction();try{try{$service->search($reviewer,[],'outer-transaction');throw new RuntimeException('Nested audit transaction accepted');}catch(LogicException){$expect($pdo->inTransaction(),'Review cannot escape durable auditing in a caller transaction');}}finally{$pdo->rollBack();}
    $pdo->exec("CREATE TRIGGER fail_privacy_search BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic review audit failure'");
    try{[$status,$body]=$search($filters);$expect($status===500&&!isset($body['data']),'Business audit failure prevents review disclosure');}finally{$pdo->exec('DROP TRIGGER fail_privacy_search');}
    $pdo->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=3 AND p.code='review_privacy_audit';CREATE TRIGGER fail_privacy_grant BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic grant audit failure'");
    try{foreach([['DELETE',1],['PUT',3]] as [$method,$target]){$before=$pdo->query('SELECT * FROM user_permissions ORDER BY id')->fetchAll();[$status]=$call('/api/v1/admin/privacy-audit/reviewers/'.$target,$method==='PUT'?$adminToken:$grantorToken,$method);$expect($status===500&&$pdo->query('SELECT * FROM user_permissions ORDER BY id')->fetchAll()===$before,'Permission mutation rolls back on failed audit');}}finally{$pdo->exec('DROP TRIGGER fail_privacy_grant');}
    $pdo->exec("CREATE TRIGGER fail_privacy_response BEFORE INSERT ON access_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic response audit failure'");
    try{[$status,$body]=$search($filters);$expect($status===503&&!isset($body['data']),'Central audit failure prevents review disclosure');}finally{$pdo->exec('DROP TRIGGER fail_privacy_response');}
    $pdo->exec('RENAME TABLE access_audit_events TO retained_privacy_events');try{[$status,$body]=$search($filters);$expect($status===500&&!isset($body['data']),'Unavailable history fails closed');}finally{$pdo->exec('RENAME TABLE retained_privacy_events TO access_audit_events');}
}finally{proc_terminate($server);proc_close($server);}
echo "Privacy audit SQL/HTTP authorization: $checks checks passed.\n";
