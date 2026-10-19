<?php
declare(strict_types=1);
// Disposable loopback SQL/HTTP only, including every preceding consent regression.
require __DIR__.'/consent-travel-enquiries.php';
use Wellness\Service\{AdminService,ConsentLock};

$checks=0;
$pdo->exec("UPDATE appointments SET status='canceled_by_clinic'; DELETE FROM time_off;
    UPDATE practitioners SET booking_mode='practitioner_managed' WHERE id IN(2,3);
    INSERT INTO locations(id,clinic_id,name,timezone) VALUES(3,1,'Other local location','UTC');
    INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(2,3);");
$set(101,0,false);$set(101,1,true);$set(101,2,true);$set(104,0,false);$set(104,1,false);$set(104,2,false);
$admin=new AdminService($database,$audit);
$payload=static fn(string $day='2035-06-10',int $practitioner=2,int $location=1):array=>[
    'practitioner_id'=>$practitioner,'location_id'=>$location,'starts_at'=>$day.'T09:00:00Z','ends_at'=>$day.'T17:00:00Z','reason_type'=>'vacation','notes'=>'Synthetic leave'];
$appointmentInsert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'admin',1)");
$visit=function(int $client=104,string $day='2035-06-10',string $status='confirmed',int $practitioner=2,int $clinic=1,int $location=1)use($appointmentInsert,$pdo):int{
    $start=$day.' 10:00:00';$end=$day.' 11:00:00';$appointmentInsert->execute([$clinic,$location,$client,$practitioner,$clinic,$start,$end,$start,$end,$status]);return (int)$pdo->lastInsertId();
};
$off=function(string $day='2035-06-10',int $practitioner=2,int $location=1)use($pdo):int{
    $pdo->prepare("INSERT INTO time_off(practitioner_id,location_id,starts_at,ends_at,reason_type,created_by) VALUES(?,?,?,?,'vacation',1)")->execute([$practitioner,$location,$day.' 09:00:00',$day.' 17:00:00']);return (int)$pdo->lastInsertId();
};
$snapshot=static fn():array=>[
    $pdo->query('SELECT * FROM time_off ORDER BY id')->fetchAll(),
    $pdo->query('SELECT id,status,starts_at,ends_at,version FROM appointments ORDER BY id')->fetchAll(),
    $pdo->query('SELECT * FROM notification_events ORDER BY id')->fetchAll(),
    (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
];
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-time-off-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-time-off-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start time-off API');fclose($pipes[0]);
$write=function(string $method,string $suffix='',?array $body=null,?string $token=null)use($call,$practitionerToken):array{return $call('/api/v1/admin/time-off'.$suffix,$token??$practitionerToken,$method,$body);};
$blocked=function(string $method,string $suffix='',?array $body=null,?string $token=null)use($write,$snapshot,$expect,$event):void{
    $before=$snapshot();[$status,$data,$cid,$raw]=$write($method,$suffix,$body,$token);
    $expect($status===403&&$data['error']['code']==='time_off_access_restricted','Affected-set authorization denies with the generic privacy code');
    $expect(!str_contains($raw,'affected_appointment')&&!str_contains($raw,'Synthetic Appointment')&&!str_contains($raw,'client_id'),'Denied change exposes no affected counts, IDs or patient names');
    $expect($snapshot()===$before,'Denied change preserves time off, appointments, notifications and business audits');
    $expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied bulk change receives access audit without disclosed resources');
};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Time-off API ready');
    [$status,$data]=$write('POST','',$payload('2035-06-01'));$emptyId=(int)($data['data']['id']??0);
    $expect($status===201&&$data['data']['affected_appointment_count']===0,'Unrelated patient restriction no longer blocks an empty period');
    [$status]=$write('PATCH','/'.$emptyId,$payload('2035-06-02'));$expect($status===200,'Empty period update remains available');
    [$status]=$write('DELETE','/'.$emptyId);$expect($status===200,'Empty period deletion remains available');
    $permitted=$visit();$hidden=$visit(101);$existing=$off();
    [$status,$data]=$write('POST','/impact',$payload());$expect($status===200&&$ids($data['data']['appointments'])===[$permitted],'Preview remains a permitted-record read, not mutation authority');
    $blocked('POST','',$payload()+['expected_affected_appointment_ids'=>[$permitted]]);
    $blocked('POST','',$payload()+['expected_affected_appointment_ids'=>[$permitted,$hidden]]);
    $blocked('POST','',$payload(),$adminToken);
    $blocked('PATCH','/'.$existing,$payload('2035-06-11')); // Releasing the old range still needs authority.
    $blocked('PATCH','/'.$existing,$payload('2035-06-11',3),$adminToken);
    $blocked('DELETE','/'.$existing);
    $clean=$off('2035-06-11');$blocked('PATCH','/'.$clean,$payload()); // The new range also matters.
    $blocked('PATCH','/'.$clean,$payload(),$adminToken);
    // The leave is clinic-wide for this practitioner, even when appointments use another location.
    $pdo->prepare('UPDATE appointments SET location_id=3 WHERE id=?')->execute([$hidden]);$blocked('POST','',$payload());
    $pdo->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$hidden]);$blocked('DELETE','/'.$existing);
    $pdo->prepare("UPDATE appointments SET status='canceled_by_client' WHERE id=?")->execute([$hidden]);
    [$status,$data]=$write('POST','',$payload()+['expected_affected_appointment_ids'=>[$permitted]]);$allowed=(int)($data['data']['id']??0);
    $expect($status===201&&$data['data']['affected_appointment_count']===1,'Canceled records do not block an otherwise permitted change');
    [$status]=$write('PATCH','/'.$allowed,$payload()+['expected_affected_appointment_ids'=>[]]);$expect($status===409,'Permitted-set stale review still requires reload');
    [$status]=$write('PATCH','/'.$allowed,$payload()+['expected_affected_appointment_ids'=>[$permitted,$permitted]]);$expect($status===422,'Duplicate review IDs remain invalid');
    [$status]=$write('PATCH','/'.$allowed,$payload()+['expected_affected_appointment_ids'=>[$permitted]]);$expect($status===200,'Permitted-set reviewed update succeeds');
    // Withdrawal after preview must be applied on every write, including delete and legacy callers.
    $set(104,2,true);$blocked('POST','',$payload());$blocked('PATCH','/'.$allowed,$payload());$blocked('DELETE','/'.$allowed);
    $set(104,2,false);$set(104,0,true);$blocked('POST','',$payload(),$adminToken);$set(104,1,false);$blocked('DELETE','/'.$allowed,null,$adminToken);
    $set(104,0,false);$set(104,3,true);
    [$status]=$write('DELETE','/'.$allowed);$expect($status===200,'Restriction for another staff member does not block the authorized actor');
    $set(104,3,false);
    [$status]=$write('DELETE','/'.$existing);$expect($status===200,'Restoration permits a previously blocked saved-period deletion');
    $pdo->prepare("UPDATE appointments SET status='confirmed' WHERE id=?")->execute([$hidden]);
    $denies(fn()=>$admin->createTimeOff($actor(),$payload(),'direct-create'),403);
    $old=$off();$denies(fn()=>$admin->updateTimeOff($actor(1,['super_admin','practitioner']),$old,$payload('2035-06-11'),'direct-update'),403);
    $denies(fn()=>$admin->deleteScheduleException($actor(),'time_off',$old,'direct-delete'),403);
    // Exact overlap boundaries exclude visits touching only the start/end, not the period itself.
    $boundary=$visit(101,'2035-06-12');$pdo->prepare("UPDATE appointments SET starts_at='2035-06-12 08:00:00',ends_at='2035-06-12 09:00:00',buffer_starts_at='2035-06-12 08:00:00',buffer_ends_at='2035-06-12 09:00:00' WHERE id=?")->execute([$boundary]);
    [$status]=$write('POST','',$payload('2035-06-12'));$expect($status===201,'Half-open boundary does not include a visit ending at leave start');
    $pdo->prepare("UPDATE appointments SET starts_at='2035-06-12 17:00:00',ends_at='2035-06-12 18:00:00',buffer_starts_at='2035-06-12 17:00:00',buffer_ends_at='2035-06-12 18:00:00' WHERE id=?")->execute([$boundary]);
    [$status]=$write('POST','',$payload('2035-06-12'));$expect($status===201,'Half-open boundary does not include a visit starting at leave end');
    // A restricted row beyond 500 permitted rows must deny before the preview cap error.
    $pdo->beginTransaction();for($i=0;$i<501;$i++)$visit(104,'2035-06-13');$lastHidden=$visit(101,'2035-06-13');$pdo->commit();
    $blocked('POST','',$payload('2035-06-13'));
    $pdo->prepare("UPDATE appointments SET status='canceled_by_clinic' WHERE id=?")->execute([$lastHidden]);
    [$status,$data]=$write('POST','',$payload('2035-06-13'));$expect($status===422&&$data['error']['code']==='too_many_affected_appointments','Fully authorized oversized periods retain the review cap');
    $large=$off('2035-06-13');[$status]=$write('DELETE','/'.$large);$expect($status===200,'Deletion authorizes the complete set without truncating at preview cap');
    // Invalid same-clinic patient references must not disappear through inner joins.
    $invalid=$visit(105,'2035-06-14');$blocked('POST','',$payload('2035-06-14'));
    $merged=$visit(107,'2035-06-15');$blocked('POST','',$payload('2035-06-15'));
    // Current role, ownership, active profile, clinic and assigned location remain required.
    foreach([['POST','',$payload('2035-06-16',3),403],['PATCH','/'.$clean,$payload('2035-06-16',3),403],['DELETE','/'.$off('2035-06-16',3),null,404],['DELETE','/'.$off('2035-06-16',5,2),null,404]] as [$method,$suffix,$body,$wanted]){
        [$status]=$write($method,$suffix,$body);$expect($status===$wanted,'Schedule ownership and foreign-clinic scope remain enforced');
    }
    $denies(fn()=>$admin->createTimeOff($actor(1,['accountant']),$payload('2035-06-17'),'role-denied'),403);
    $denies(fn()=>$admin->createTimeOff($actor(101,['super_admin'],[],'client'),$payload('2035-06-17'),'type-denied'),403);
    $pdo->exec('UPDATE practitioners SET active=0 WHERE id=2');try{[$status]=$write('POST','',$payload('2035-06-17'));$expect($status===403,'Inactive own profile cannot write');}finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=2');}
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=2");try{$denies(fn()=>$admin->createTimeOff($actor(),$payload('2035-06-17'),'inactive-actor'),403);}finally{$pdo->exec("UPDATE users SET status='active' WHERE id=2");}
    $pdo->exec('UPDATE practitioner_locations SET active=0 WHERE practitioner_id=2 AND location_id=3');try{[$status]=$write('POST','',$payload('2035-06-17',2,3));$expect($status===403,'Revoked location assignment cannot write');}finally{$pdo->exec('UPDATE practitioner_locations SET active=1 WHERE practitioner_id=2 AND location_id=3');}
    // Every time-off mutation, including delete, rolls back on business audit failure.
    $rollback=$off('2035-06-18');$pdo->exec("CREATE TRIGGER fail_time_off_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{foreach([['POST','',$payload('2035-06-18')],['PATCH','/'.$rollback,$payload('2035-06-19')],['DELETE','/'.$rollback,null]] as [$method,$suffix,$body]){$before=$snapshot();[$status]=$write($method,$suffix,$body);$expect($status===500&&$snapshot()===$before,'Audit failure rolls back the complete time-off mutation');}}
    finally{$pdo->exec('DROP TRIGGER fail_time_off_audit');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{$before=$snapshot();[$status]=$write('DELETE','/'.$rollback);$expect($status===503&&$snapshot()===$before,'Concurrent withdrawal lock blocks all mutation side effects');}finally{$lock->release();}
    [$status]=$write('DELETE','/'.$rollback);$expect($status===200,'Mutation succeeds after the consent lock is released');
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_time_off_directives');
    try{foreach([['POST','',$payload('2035-06-20')],['PATCH','/'.$clean,$payload('2035-06-20')],['DELETE','/'.$clean,null]] as [$method,$suffix,$body]){$before=$snapshot();[$status]=$write($method,$suffix,$body);$expect($status===500&&$snapshot()===$before,'Missing policy storage blocks even empty-period writes');}}
    finally{$pdo->exec('RENAME TABLE retained_time_off_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Bulk time-off SQL/HTTP authorization: $checks checks passed.\n";
