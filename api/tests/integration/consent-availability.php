<?php
declare(strict_types=1);
require __DIR__.'/consent-address-coverage.php';
use Wellness\Service\{AdminService,ConsentLock};
$checks=0;
$pdo->exec("UPDATE appointments SET status='canceled_by_clinic';DELETE FROM availability_rules;DELETE FROM availability_overrides;
    UPDATE practitioners SET booking_mode='practitioner_managed',active=1 WHERE id IN(2,3);
    UPDATE practitioner_locations SET active=1 WHERE practitioner_id=2;
    DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=2 AND p.code='schedule_for_other_practitioners';");
$set(101,0,false);$set(101,1,true);$set(101,2,true);$set(104,0,false);$set(104,1,false);$set(104,2,false);
$admin=new AdminService($database,$audit);
$ruleBody=['practitioner_id'=>2,'location_id'=>1,'weekday'=>1,'start_time'=>'09:00:00','end_time'=>'17:00:00','valid_from'=>'2035-06-04','valid_until'=>null,'recurrence_interval_weeks'=>2];
$overrideBody=['practitioner_id'=>2,'location_id'=>1,'starts_at'=>'2035-06-04T09:00:00-04:00','ends_at'=>'2035-06-04T17:00:00-04:00','override_type'=>'blocked','reason'=>'Synthetic change'];
$ruleRecord=function(array $change=[])use($pdo,$ruleBody):int{$b=array_replace($ruleBody,$change);$pdo->prepare('INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from,valid_until,recurrence_interval_weeks) VALUES(?,?,?,?,?,?,?,?)')->execute([$b['practitioner_id'],$b['location_id'],$b['weekday'],$b['start_time'],$b['end_time'],$b['valid_from'],$b['valid_until'],$b['recurrence_interval_weeks']]);return (int)$pdo->lastInsertId();};
$overrideRecord=function(string $day='2035-06-04',int $person=2,int $location=1)use($pdo):int{$pdo->prepare("INSERT INTO availability_overrides(practitioner_id,location_id,starts_at,ends_at,override_type,created_by) VALUES(?,?,?,?,'blocked',1)")->execute([$person,$location,$day.' 13:00:00',$day.' 21:00:00']);return (int)$pdo->lastInsertId();};
$insert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'admin',1)");
$visit=function(string $day='2035-06-04',int $client=101,int $location=1,string $status='confirmed',int $clinic=1,int $person=2)use($pdo,$insert):int{$from=$day.' 14:00:00';$to=$day.' 15:00:00';$insert->execute([$clinic,$location,$client,$person,$clinic,$from,$to,$from,$to,$status]);return (int)$pdo->lastInsertId();};
$snapshot=static fn():array=>[$pdo->query('SELECT * FROM availability_rules ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM availability_overrides ORDER BY id')->fetchAll(),$pdo->query('SELECT id,status,starts_at,ends_at,buffer_starts_at,buffer_ends_at,version FROM appointments ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM notification_events ORDER BY id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
unset($env['ACCESS_AUDIT_TEST_COVERAGE']);
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-availability-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-availability-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start availability API');fclose($pipes[0]);
$write=fn(string $resource,string $method='POST',?array $body=null,?string $token=null)=>$call('/api/v1/admin/'.$resource,$token??$practitionerToken,$method,$body);
$blocked=function(string $resource,string $method,?array $body=null,?string $token=null)use($write,$expect,$snapshot,$event):void{
    $before=$snapshot();[$status,$data,$cid,$raw]=$write($resource,$method,$body,$token);
    $expect($status===403&&$data['error']['code']==='schedule_access_restricted','Restricted affected set requires generic schedule review');
    $expect(!str_contains($raw,'affected_appointment')&&!str_contains($raw,'Restricted Patient')&&!str_contains($raw,'client_id'),'No hidden affected count, patient identity or appointment IDs in denial');
    $expect($snapshot()===$before,'Denied change preserves rules, overrides, appointments, notifications and business audits');
    $expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Schedule denial audited without disclosed resources');
};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Availability API ready');
    [$status,$data]=$write('availability-rules','POST',$ruleBody);$cleanRule=(int)($data['data']['id']??0);$expect($status===201,'Empty recurring period is available despite unrelated restrictions');
    [$status]=$write('availability-rules/'.$cleanRule,'PATCH',array_replace($ruleBody,['weekday'=>2]));$expect($status===200,'Permitted empty rule update succeeds');
    [$status]=$write('availability-rules/'.$cleanRule,'DELETE');$expect($status===200,'Permitted archive succeeds');
    [$status]=$write('availability-rules/'.$cleanRule,'DELETE');$expect($status===404,'Already archived rule remains unavailable');
    [$status,$data]=$write('availability-overrides','POST',$overrideBody);$cleanOverride=(int)($data['data']['id']??0);$expect($status===201,'Empty override creation succeeds');
    [$status]=$write('availability-overrides/'.$cleanOverride,'PATCH',array_replace($overrideBody,['override_type'=>'available']));$expect($status===200,'Permitted override type change succeeds');
    [$status]=$write('availability-overrides/'.$cleanOverride,'DELETE');$expect($status===200,'Permitted override deletion succeeds');
    $hidden=$visit();$visible=$visit('2035-06-04',104);$oldRule=$ruleRecord();$oldOverride=$overrideRecord();
    $blocked('availability-rules','POST',$ruleBody);$blocked('availability-rules','POST',$ruleBody,$adminToken);
    foreach(['available','blocked'] as $type)$blocked('availability-overrides','POST',array_replace($overrideBody,['override_type'=>$type]));
    $blocked('availability-rules/'.$oldRule,'PATCH',array_replace($ruleBody,['weekday'=>2]));$blocked('availability-rules/'.$oldRule,'DELETE');
    $emptyRule=$ruleRecord(['weekday'=>2]);$blocked('availability-rules/'.$emptyRule,'PATCH',$ruleBody);
    $blocked('availability-rules/'.$oldRule,'PATCH',array_replace($ruleBody,['practitioner_id'=>3,'weekday'=>2]),$adminToken);
    $elsewhere=array_replace($overrideBody,['starts_at'=>'2035-06-05T09:00:00-04:00','ends_at'=>'2035-06-05T17:00:00-04:00']);
    $blocked('availability-overrides/'.$oldOverride,'PATCH',$elsewhere);$blocked('availability-overrides/'.$oldOverride,'DELETE');
    $emptyOverride=$overrideRecord('2035-06-05');$blocked('availability-overrides/'.$emptyOverride,'PATCH',$overrideBody);
    $blocked('availability-overrides/'.$oldOverride,'PATCH',array_replace($elsewhere,['practitioner_id'=>3]),$adminToken);
    $pdo->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$hidden]);$blocked('availability-rules/'.$oldRule,'DELETE');
    $pdo->prepare("UPDATE appointments SET status='canceled_by_client' WHERE id=?")->execute([$hidden]);
    [$status]=$write('availability-rules','POST',$ruleBody);$expect($status===201,'Canceled hidden patient does not block a permitted rule');
    [$status]=$write('availability-overrides','POST',$overrideBody);$expect($status===201,'Permitted affected override succeeds');
    $set(104,2,true);$blocked('availability-rules/'.$oldRule,'DELETE');$blocked('availability-overrides/'.$oldOverride,'DELETE');$set(104,2,false);
    $set(104,0,true);$set(104,1,false);$blocked('availability-rules','POST',$ruleBody,$adminToken);$set(104,0,false);
    $set(104,3,true);[$status]=$write('availability-rules','POST',$ruleBody);$expect($status===201,'Another staff member restriction does not block acting practitioner');$set(104,3,false);
    $offWeek=$visit('2035-06-11');[$status]=$write('availability-rules','POST',$ruleBody);$expect($status===201,'Alternate-week phase excludes non-occurrences');
    $bounded=array_replace($ruleBody,['valid_until'=>'2035-06-17']);$after=$visit('2035-06-18');
    [$status]=$write('availability-rules','POST',$bounded);$expect($status===201,'Effective end date excludes later hidden occurrence');
    $blocked('availability-rules','POST',$ruleBody);
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");
    $far=(new DateTimeImmutable('9000-01-01'))->modify('next monday')->format('Y-m-d');$farId=$visit($far);$blocked('availability-rules','POST',array_replace($ruleBody,['recurrence_interval_weeks'=>1]));
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");
    $pdo->beginTransaction();for($i=0;$i<257;$i++)$visit('2035-06-05');$visit('2035-06-18');$pdo->commit();$blocked('availability-rules','POST',$ruleBody);
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");
    $buffer=$visit();$pdo->prepare("UPDATE appointments SET starts_at='2035-06-04 21:00:00',ends_at='2035-06-04 22:00:00',buffer_starts_at='2035-06-04 20:45:00',buffer_ends_at='2035-06-04 22:00:00' WHERE id=?")->execute([$buffer]);
    $blocked('availability-rules','POST',$ruleBody);$blocked('availability-overrides','POST',$overrideBody);
    $pdo->prepare("UPDATE appointments SET buffer_starts_at=starts_at WHERE id=?")->execute([$buffer]);
    [$status]=$write('availability-rules','POST',$ruleBody);$expect($status===201,'Touching only the window end is not overlap');
    [$status]=$write('availability-overrides','POST',$overrideBody);$expect($status===201,'Override uses half-open boundaries too');
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");$visit('2035-06-04',101,3);
    [$status]=$write('availability-rules','POST',$ruleBody);$expect($status===201,'Other location patient does not block this location rule');
    [$status]=$write('availability-overrides','POST',$overrideBody);$expect($status===201,'Override scope remains location-specific');
    $visit('2035-06-04',105);$blocked('availability-rules','POST',$ruleBody);$blocked('availability-overrides','POST',$overrideBody);
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");$visit('2035-06-04',107);$blocked('availability-rules','POST',$ruleBody);
    $pdo->exec("UPDATE appointments SET status='canceled_by_clinic'");
    foreach([['valid_from'=>'2035-02-30'],['valid_until'=>'2035-02-30'],['valid_from'=>'2035-06-04junk']] as $bad){[$status]=$write('availability-rules','POST',array_replace($ruleBody,$bad));$expect($status===422,'Invalid calendar dates cannot normalize to an unreviewed rule');}
    $pdo->exec("INSERT IGNORE INTO user_permissions(user_id,permission_id,assigned_by) SELECT 2,id,1 FROM permissions WHERE code='schedule_for_other_practitioners'");
    [$status]=$write('availability-rules','POST',array_replace($ruleBody,['practitioner_id'=>3]));$expect($status===403,'Booking delegation does not imply another practitioner schedule access');
    $foreignRule=$ruleRecord(['practitioner_id'=>5,'location_id'=>2]);[$status]=$write('availability-rules/'.$foreignRule,'DELETE');$expect($status===404,'Foreign clinic rule is concealed');
    $foreignOverride=$overrideRecord('2035-06-04',5,2);[$status]=$write('availability-overrides/'.$foreignOverride,'DELETE');$expect($status===404,'Foreign clinic override is concealed');
    $denies(fn()=>$admin->createAvailability($actor(1,['accountant']),$ruleBody,'denied-role'),403);
    $denies(fn()=>$admin->createAvailabilityOverride($actor(101,['super_admin'],[],'client'),$overrideBody,'denied-client'),403);
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=2");try{$denies(fn()=>$admin->createAvailability($actor(),$ruleBody,'inactive-actor'),403);}finally{$pdo->exec("UPDATE users SET status='active' WHERE id=2");}
    $pdo->exec('UPDATE practitioner_locations SET active=0 WHERE practitioner_id=2 AND location_id=1');try{
        foreach([['availability-overrides','POST',$overrideBody],['availability-rules/'.$oldRule,'DELETE',null],['availability-overrides/'.$oldOverride,'DELETE',null],['availability-rules/'.$oldRule,'PATCH',$ruleBody],['availability-overrides/'.$oldOverride,'PATCH',$overrideBody]] as [$path,$method,$body]){
            $before=$snapshot();[$status]=$write($path,$method,$body);$expect($status===403&&$snapshot()===$before,'Revoked old location assignment cannot create, update or remove schedule items');
        }
    }finally{$pdo->exec('UPDATE practitioner_locations SET active=1 WHERE practitioner_id=2 AND location_id=1');}
    $pdo->exec("UPDATE practitioners SET booking_mode='clinic_managed' WHERE id=2");try{[$status]=$write('availability-rules','POST',$ruleBody);$expect($status===403,'Clinic-managed own profile cannot mutate hours');}finally{$pdo->exec("UPDATE practitioners SET booking_mode='practitioner_managed' WHERE id=2");}
    $pdo->beginTransaction();try{try{$admin->createAvailability($actor(),$ruleBody,'nested');throw new RuntimeException('Nested mutation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Nested rejection preserves caller transaction');}}finally{$pdo->rollBack();}
    // All six mutation/audit pairs must commit atomically.
    $safeRule=$ruleRecord();$safeOverride=$overrideRecord();
    $operations=[['availability-rules','POST',$ruleBody],['availability-rules/'.$safeRule,'PATCH',array_replace($ruleBody,['weekday'=>2])],['availability-rules/'.$safeRule,'DELETE',null],['availability-overrides','POST',$overrideBody],['availability-overrides/'.$safeOverride,'PATCH',$elsewhere],['availability-overrides/'.$safeOverride,'DELETE',null]];
    $pdo->exec("CREATE TRIGGER fail_availability_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{foreach($operations as [$path,$method,$body]){$before=$snapshot();[$status]=$write($path,$method,$body);$expect($status===500&&$snapshot()===$before,'Failed business audit rolls back the entire schedule mutation');}}finally{$pdo->exec('DROP TRIGGER fail_availability_audit');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{$before=$snapshot();[$status]=$write('availability-rules','POST',$ruleBody);$expect($status===503&&$snapshot()===$before,'Withdrawal lock blocks mutation side effects');}finally{$lock->release();}
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_availability_directives');
    try{foreach($operations as [$path,$method,$body]){$before=$snapshot();[$status]=$write($path,$method,$body);$expect($status===500&&$snapshot()===$before,'Missing policy storage blocks every schedule mutation, even empty windows');}}finally{$pdo->exec('RENAME TABLE retained_availability_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Availability rule/override SQL/HTTP authorization: $checks checks passed.\n";
