<?php
declare(strict_types=1);
// Synthetic localhost SQL/HTTP only; retains all preceding consent authorization suites.
require __DIR__.'/consent-time-off.php';
use Wellness\Service\{ClientService,ConsentLock};

$checks=0;
$pdo->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=2 AND p.code='schedule_for_other_practitioners';
    INSERT IGNORE INTO user_permissions(user_id,permission_id) SELECT 2,id FROM permissions WHERE code='add_clients';
    UPDATE users SET given_name='Restricted',family_name='Patient',display_name='Restricted Patient' WHERE id=101;
    UPDATE users SET given_name='Permitted',family_name='Patient',display_name='Permitted Patient' WHERE id=104;
    INSERT INTO users(id,clinic_id,given_name,family_name,display_name,email,user_type,status) VALUES(9001,1,'Unrelated','Patient','Unrelated Patient','unrelated-registration@example.test','client','active');
    UPDATE users SET given_name='Legacy',family_name='Name',display_name='Legacy Name' WHERE id=107;");
$set(101,0,false);$set(101,1,true);$set(101,2,true);$set(104,0,false);$set(104,1,false);$set(104,2,false);
$clients=new ClientService($database,$audit);$counter=0;
$body=function(string $given='New',string $family='Registration')use(&$counter):array{return ['given_name'=>$given,'family_name'=>$family,'email'=>'registration-'.++$counter.'@example.test'];};
$snapshot=static fn():array=>[
    $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_profiles ORDER BY user_id')->fetchAll(),
    $pdo->query('SELECT * FROM client_email_addresses ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_contact_addresses ORDER BY client_id')->fetchAll(),
    $pdo->query('SELECT * FROM client_consent_directives ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM client_consent_events ORDER BY id')->fetchAll(),
    (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
];
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-registration-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-registration-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start registration API');fclose($pipes[0]);
$register=fn(array $body,?string $token=null)=>$call('/api/v1/clients',$token??$practitionerToken,'POST',$body);
$review=function(array $body,?string $token=null)use($register,$expect,$snapshot,$event):void{
    $before=$snapshot();[$status,$data,$cid,$raw]=$register($body,$token);
    $expect($status===409&&$data['error']['code']==='client_registration_review','Hidden match/collision requires generic registration review');
    $expect(empty($data['error']['fields'])&&!str_contains($raw,'client101')&&!str_contains($raw,'Restricted Patient')&&!str_contains($raw,'Synthetic Street'),'Review response discloses no candidates, contact information or address');
    $expect($snapshot()===$before,'Refused registration creates no new relationship, patient, consent, profile, alias or business audit');
    $row=$event($cid)[0];$expect($row['outcome']==='denied'&&json_decode($row['metadata'],true)['resources']===[],'Registration privacy refusal is audited without disclosed resource IDs');
};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Registration API ready');
    [$status,$data]=$register($body('Distinct','Practitioner'));$created=(int)($data['data']['id']??0);$expect($status===201&&$created>0,'Unrelated restriction no longer blocks authorized practitioner creation');
    [$status,$data]=$call('/api/v1/booking-clients?q=Distinct',$practitionerToken);$expect($status===200&&in_array($created,$ids($data['data']['items']),true),'Only committed creation establishes the intended booking relationship');
    [$status]=$register($body('Distinct','Operator'),$adminToken);$expect($status===201,'Restricted administrator can register an unrelated patient');
    foreach([$practitionerToken,$adminToken] as $token){
        [$status,$data,$cid,$raw]=$register($body('Permitted','Patient'),$token);
        $expect($status===409&&$data['error']['code']==='possible_duplicate'&&$ids($data['error']['fields']['candidates'])===[104],'Permitted duplicate review retains existing candidates');
        $expect(!str_contains($raw,'date_of_birth'),'Duplicate review minimizes fields, omitting birthdate');
        $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$expect($refs===[['type'=>'client','id'=>104]],'Candidate access audit records only disclosed permitted IDs');
        $expect(!str_contains($event($cid)[0]['metadata'],'example.test'),'Candidate audit omits contact content');
    }
    [$status]=$register($body('Permitted','Patient')+['confirm_possible_duplicate'=>true],$adminToken);$expect($status===201,'Operator may explicitly confirm a wholly permitted duplicate');
    $review($body('Restricted','Patient'));$review($body('Restricted','Patient'),$adminToken);
    $review($body('Restricted','Patient')+['confirm_possible_duplicate'=>true],$adminToken);
    $review($body('Unrelated','Patient')); // No positive practitioner relationship, even without a directive.
    $review($body('Legacy','Name'));$review($body('Legacy','Name'),$adminToken); // Merged name resolves to restricted survivor 101.
    $collision=$body('Different','Name');$collision['email']='client101@example.test';$review($collision);
    $pdo->exec("INSERT INTO client_email_addresses(clinic_id,client_id,email,is_primary,source) VALUES(1,101,'hidden-alias@example.test',0,'staff')");
    $collision['email']='hidden-alias@example.test';$review($collision,$adminToken);
    $collision['email']='admin@example.test';$review($collision); // Staff account collision uses the same response.
    // Check all matching records before the 10-candidate cap or create-anyway confirmation.
    $insert=$pdo->prepare("INSERT INTO users(clinic_id,given_name,family_name,display_name,email,user_type,status) VALUES(1,'Crowded','Name','Crowded Name',?,'client','active')");
    for($i=0;$i<12;$i++)$insert->execute(['crowded-'.$i.'@example.test']);
    $hiddenCrowded=(int)$pdo->lastInsertId();$set($hiddenCrowded,1,true);
    $review($body('Crowded','Name'),$adminToken);$review($body('Crowded','Name')+['confirm_possible_duplicate'=>true],$adminToken);
    $set($hiddenCrowded,1,false);[$status,$data]=$register($body('Crowded','Name'),$adminToken);$expect($status===409&&count($data['error']['fields']['candidates'])===10,'Wholly permitted duplicate review retains its cap');
    // Recheck stale confirmation and global/named restoration, including delegated scope.
    $set(104,1,true);$review($body('Permitted','Patient')+['confirm_possible_duplicate'=>true],$adminToken);$set(104,1,false);
    $set(101,0,true);$set(101,1,false);$review($body('Restricted','Patient')+['confirm_possible_duplicate'=>true],$adminToken);
    $set(101,0,false);[$status,$data]=$register($body('Restricted','Patient'),$adminToken);$expect($status===409&&$ids($data['error']['fields']['candidates'])===[101],'Restoration returns only the permitted current record');
    $pdo->exec("INSERT IGNORE INTO user_permissions(user_id,permission_id) SELECT 2,id FROM permissions WHERE code='schedule_for_other_practitioners'");
    [$status,$data]=$register($body('Unrelated','Patient'));$expect($status===409&&$ids($data['error']['fields']['candidates'])===[9001],'Existing delegated booking scope permits duplicate contact review');
    $review($body('Restricted','Patient')); // Target 2 still restricted.
    $pdo->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=2 AND p.code='schedule_for_other_practitioners'");
    $denies(fn()=>$clients->save($actor(2,['super_admin','practitioner'],['add_clients']),$body('Restricted','Patient'),'direct-refusal'),409);
    $denies(fn()=>$clients->save($actor(1,['accountant']),$body(),'wrong-role'),403);
    $denies(fn()=>$clients->save($actor(101,['super_admin'],[],'client'),$body(),'wrong-type'),403);
    $denies(fn()=>$clients->save($actor(),$body(),'missing-capability'),403);
    $pdo->exec('UPDATE practitioners SET active=0 WHERE id=2');try{[$status]=$register($body());$expect($status===403,'Inactive practitioner cannot establish new client access');}finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=2');}
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=2");try{$denies(fn()=>$clients->save($actor(2,['practitioner'],['add_clients']),$body(),'inactive-actor'),403);}finally{$pdo->exec("UPDATE users SET status='active' WHERE id=2");}
    [$status]=$call('/api/v1/clients',$practitionerToken,'POST',$body(),'audit-b.test');$expect($status===403,'Host/clinic switch cannot authorize registration');
    $foreign=$body('Foreign','Only');$pdo->prepare("INSERT INTO users(clinic_id,given_name,family_name,display_name,email,user_type,status) VALUES(2,'Foreign','Only','Foreign Only',?,'client','active')")->execute([$foreign['email']]);
    [$status]=$register($foreign);$expect($status===201,'Other clinic identities are neither disclosed nor considered local duplicates');
    // Editing an accessible record cannot rename it into a hidden matching identity.
    $record=$clients->get($actor(1,['super_admin']),$created,'registration-edit-read');$before=$snapshot();
    $edit=array_replace($body('Restricted','Patient'),['email'=>$record['email'],'revision'=>$record['revision']]);$set(101,1,true);$before=$snapshot();
    [$status,$data]=$call('/api/v1/clients/'.$created,$adminToken,'PATCH',$edit);$expect($status===409&&$data['error']['code']==='client_registration_review'&&$snapshot()===$before,'Identity edit cannot bypass a restricted registration match');
    // Updating the known patient's non-identity fields must not be blocked by another person's name.
    $pdo->exec("UPDATE users SET given_name='Distinct',family_name='Practitioner',display_name='Distinct Practitioner' WHERE id=101");
    try{
        $record=$clients->get($actor(1,['super_admin']),$created,'registration-phone-read');
        $update=['given_name'=>$record['given_name'],'family_name'=>$record['family_name'],'email'=>$record['email'],'revision'=>$record['revision'],'phone'=>'+14165550999'];
        [$status,$data]=$call('/api/v1/clients/'.$created,$adminToken,'PATCH',$update);
        $expect($status===200&&$data['data']['phone']==='+14165550999','Non-identity update to a permitted patient does not require review for a different hidden namesake');
    }finally{$pdo->exec("UPDATE users SET given_name='Restricted',family_name='Patient',display_name='Restricted Patient' WHERE id=101");}
    $pdo->exec("CREATE TRIGGER fail_registration_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{$before=$snapshot();[$status]=$register($body('Rollback','Registration'));$expect($status===500&&$snapshot()===$before,'Audit failure rolls back patient/profile/alias/address and relationship creation');}finally{$pdo->exec('DROP TRIGGER fail_registration_audit');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{$before=$snapshot();[$status]=$register($body('Locked','Registration'));$expect($status===503&&$snapshot()===$before,'Withdrawal lock prevents concurrent registration changes');}finally{$lock->release();}
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_registration_directives');
    try{$before=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();[$status]=$register($body('Storage','Unavailable'));$expect($status===500&&(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'Missing consent storage blocks even unmatched new registrations');}
    finally{$pdo->exec('RENAME TABLE retained_registration_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Client registration SQL/HTTP authorization: $checks checks passed.\n";
