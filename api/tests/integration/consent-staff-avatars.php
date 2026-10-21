<?php
declare(strict_types=1);
require __DIR__.'/access-audit.php';
use Wellness\Service\{ProfileService,ClientConsentService,ConsentLock};
use Wellness\Http\ApiException;
$checks=0;$photos=new ProfileService($database,$audit);$policy=new ClientConsentService($database,$audit);
foreach([0,1,2] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'avatar-policy');
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','avatar-admin');");
$images=['UklGRiIAAABXRUJQVlA4TBYAAAAvP8APAAdQwChg/wNAQvj/Xovof+oH', 'UklGRiIAAABXRUJQVlA4TBYAAAAvP8APAAfQ0v5H/wNAQvj/Xovof+oH', 'UklGRiIAAABXRUJQVlA4TBUAAAAvP8APAAcQ/Y/+B4CE8P+9FtH/1A8A'];
[$first,$second,$patientImage]=$images;$body=['image_base64'=>$first];
$insert=function(int $user,string $encoded)use($pdo):void{$data=base64_decode($encoded);$pdo->prepare("INSERT INTO user_profile_images(user_id,mime_type,image_data,byte_size,content_hash,updated_by) VALUES(?,'image/webp',?,?,?,1)")->execute([$user,$data,strlen($data),hash('sha256',$data)]);};
foreach([1,2,3,5,6] as $id)$insert($id,$first);$insert(101,$patientImage);
$snapshot=fn()=>[$pdo->query('SELECT * FROM user_profile_images ORDER BY user_id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$patients=fn()=>[$pdo->query('SELECT * FROM appointments ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_consent_directives ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot):void{$before=$snapshot();try{$work();throw new RuntimeException('Expected avatar refusal');}catch(ApiException $e){$expect($e->status===$status,'Avatar refusal '.$status.' got '.$e->status);}$expect($snapshot()===$before,'Refusal preserves all photos and business audits');};
$ops=fn($a,$target=null)=>[fn()=>$photos->avatar($a,$target),fn()=>$photos->save($a,$body,'denied',$target),fn()=>$photos->delete($a,'denied',$target)];
foreach([$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin']),$actor(3,['super_admin'])] as $bad){foreach($ops($bad,2) as $op)$deny($op);}
foreach([$actor(2),$actor(3,['clinic_admin']),$actor(3,['reception']),$actor(3,['accountant'])] as $bad)foreach($ops($bad,$bad->userId) as $op)$deny($op);
foreach([101,105,5,99999] as $bad)foreach($ops($operator,$bad) as $op)$deny($op,404);
foreach([false,true] as $explicit){$target=$explicit?2:null;$stale=$actor(1,['super_admin']);$pdo->exec("UPDATE users SET status='inactive' WHERE id=1");foreach($ops($stale,$target) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=1");}
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);foreach($ops($operator,2) as $op)$deny($op);$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin';UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator,2) as $op)$deny($op);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
foreach([null,[],true,'%%%'] as $invalid)$deny(fn()=>$photos->save($operator,['image_base64'=>$invalid],'invalid',2),422);
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/avatars-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/avatars-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start avatar API');fclose($pipes[0]);
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $before=$patients();
    [$status,$data,$cid]=$call('/api/v1/admin/users/2/avatar',$adminToken);$expect($status===200&&$data['data']['image_base64']===$first,'Staff moderation read works despite unrelated/global patient restriction');
    $expect(json_decode($event($cid)[0]['metadata'],true)['resources']===[['type'=>'user','id'=>2]]&&!str_contains(json_encode($event($cid)),$first),'Read audit identifies staff user without copying image');
    [$status,$data,$cid]=$call('/api/v1/profile/avatar',$practitionerToken);$expect($status===200&&$data['data']['image_base64']===$first,'Own staff photo remains accessible under unrelated restrictions');$expect(json_decode($event($cid)[0]['metadata'],true)['resources']===[['type'=>'user','id'=>2]],'Own-photo read gets a concrete user audit reference');
    foreach([['GET',null],['PUT',$body],['DELETE',null]] as [$method,$payload]){
        foreach([[$practitionerToken,2],[$staffToken('avatar-admin','Wellness.ClinicAdmin'),3]] as [$token,$id]){[$status,$data,$cid]=$call("/api/v1/admin/users/$id/avatar",$token,$method,$payload);$expect($status===403&&$event($cid)[0]['outcome']==='denied','Admin route requires Super Admin even when target is self');}
        foreach([101,5,99999] as $id){$beforeWrite=$snapshot();[$status,$data,$cid,$raw]=$call("/api/v1/admin/users/$id/avatar",$adminToken,$method,$payload);$expect($status===404&&$snapshot()===$beforeWrite,'Patient/foreign/missing avatar concealed without writes');$expect(!str_contains($raw,$patientImage)&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied response and audit disclose no patient photo');}
    }
    [$status]=$call('/api/v1/admin/users/2/avatar',$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host switching cannot transfer avatar scope');
    [$status,$data]=$call('/api/v1/profile/avatar',$practitionerToken,'PUT',['image_base64'=>$second,'user_id'=>101,'clinic_id'=>2]);$expect($status===200&&$photos->avatar($operator,2)['image_base64']===$second,'Own upload cannot be redirected through body IDs');
    [$status]=$call('/api/v1/admin/users/2/avatar',$adminToken,'PUT',$body);$expect($status===200&&$photos->avatar($practitioner)['image_base64']===$first,'Authorized moderator can replace a staff photo');
    $pdo->exec("CREATE TRIGGER avatar_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic avatar audit unavailable'");
    foreach([fn()=>$photos->save($operator,['image_base64'=>$second],'rollback',2),fn()=>$photos->delete($operator,'rollback',2)] as $op){$beforeWrite=$snapshot();try{$op();throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$beforeWrite,'Audit failure rolls back image replacement or removal');}$pdo->exec('DROP TRIGGER avatar_audit_failure');
    [$status,$data]=$call('/api/v1/admin/users/6/avatar',$adminToken,'DELETE');$expect($status===200&&$data['data']['deleted']===true,'Inactive staff photo can still be removed by an active moderator');
    [$status,$data,$cid]=$call('/api/v1/admin/users/2/avatar',$adminToken,'DELETE');$expect($status===200&&$data['data']['deleted']===true,'Moderation removal allowed despite unrelated privacy instructions');
    [$status,$data]=$call('/api/v1/profile/avatar',$practitionerToken);$expect($status===200&&$data['data']['image_base64']===null,'Removed photo is no longer returned');
    [$status]=$call('/api/v1/profile/avatar',$practitionerToken,'PUT',$body);$expect($status===200,'Self-service can recreate a removed own image');
    [$status,$data]=$call('/api/v1/profile/avatar',$practitionerToken,'DELETE');$expect($status===200&&$data['data']['deleted']===true,'Self-service removal remains authorized');
    $q=$pdo->prepare('SELECT image_data FROM user_profile_images WHERE user_id=101');$q->execute();$expect(base64_encode($q->fetchColumn())===$patientImage&&$patients()===$before,'Staff image operations preserve patient images, accounts, bookings and directives');
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);try{$deny(fn()=>$photos->delete($operator,'busy',2),503);}finally{$blocking->release();}
    $pdo->beginTransaction();try{try{$photos->avatar($operator);throw new RuntimeException('Nested operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Caller transaction preserved on refusal');}}finally{$pdo->rollBack();}
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized staff avatars: $checks checks passed; local synthetic SQL/HTTP only.\n";
