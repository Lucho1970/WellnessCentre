<?php
declare(strict_types=1);
// Synthetic loopback SQL/HTTP only; never loads deployment credentials or patient data.
require __DIR__.'/access-audit.php';
use Wellness\Service\{DashboardService,ClientConsentService};
use Wellness\Http\ApiException;
$checks=0;$widgets=new DashboardService($database,$audit);
$built=(new ReflectionMethod(DashboardService::class,'builtIns'))->invoke($widgets);
$definition=$built['my_next_appointment'];$definition['id']='custom_next';
$policy=new ClientConsentService($database,$audit);
foreach([0,1,2] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'widget-restrict');
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','widget-clinic-admin');
    INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    UPDATE appointments SET status='canceled_by_clinic';");
// Earliest is restricted; next is another practitioner; only the third is eligible.
$make=function(int $client,int $practitioner,int $minutes)use($pdo):int{
    $pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(1,1,?,?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),'confirmed','admin',1)")->execute([$client,$practitioner,$minutes,$minutes+10,$minutes,$minutes+10]);return (int)$pdo->lastInsertId();
};
$hidden=$make(101,2,5);$other=$make(104,3,10);$visible=$make(104,2,15);
$snapshot=fn()=>[$pdo->query('SELECT * FROM dashboard_widgets ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM dashboard_widget_versions ORDER BY id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot):void{$before=$snapshot();try{$work();throw new RuntimeException('Expected widget denial');}catch(ApiException $e){$expect($e->status===$status,'Widget authorization status '.$status);}$expect($snapshot()===$before,'Denied operation preserves widget/version/audit state');};
$ops=fn($a)=>[fn()=>$widgets->adminList($a),fn()=>$widgets->upload($a,['definition'=>$definition],'denied'),fn()=>$widgets->versions($a,'custom_next'),fn()=>$widgets->restore($a,'custom_next',1,'denied'),fn()=>$widgets->setEnabled($a,'custom_next',['enabled'=>false],'denied')];
foreach([$actor(2),$actor(3,['clinic_admin']),$actor(3,['reception']),$actor(3,['accountant']),$actor(101,['super_admin'],[],'client'),$actor(5,['super_admin']),$actor(6,['super_admin']),$actor(3,['super_admin']),$actor(1,['super_admin'],[],'application_admin'),$actor(3,['clinic_admin'],['review_privacy_audit'])] as $bad)foreach($ops($bad) as $op)$deny($op);
// A valid actor from another clinic can manage only that clinic, even with a forged body clinic.
$foreign=$actor(5,['super_admin'],[],'staff',2);
$widgets->upload($foreign,['definition'=>$definition,'clinic_id'=>1],'foreign-widget');
$expect(count($widgets->versions($operator,'custom_next')['versions'])===0,'Foreign versions concealed');
$deny(fn()=>$widgets->restore($operator,'custom_next',1,'foreign-restore'),404);
$deny(fn()=>$widgets->setEnabled($operator,'custom_next',['enabled'=>true],'foreign-toggle'),404);
foreach(['inactive'] as $state){$pdo->exec("UPDATE users SET status='$state' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=1");}
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'; UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/widgets-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/widgets-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start widget API');fclose($pipes[0]);
$path='/api/v1/admin/dashboard-widgets';$write=fn($method,$body=null,$suffix='',$token=null)=>$call($path.$suffix,$token??$adminToken,$method,$body);
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    [$status]=$call($path,$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host switching cannot select foreign clinic widget management');
    [$status]=$write('GET');$expect($status===200,'Configuration readable despite unrelated/global patient restrictions');
    [$status,$data,$cid]=$write('POST',['definition'=>$definition,'clinic_id'=>2]);$expect($status===200||$status===201,'Safe declarative widget published under consent restrictions');$id=(int)$data['data']['widget_id'];
    $expect(json_decode($event($cid)[0]['metadata'],true)['resources']===[['type'=>'dashboard_widget','id'=>$id]],'Publication disclosure audited by numeric widget ID');
    [$status,$data,$cid]=$write('GET',null,'/custom_next/versions');$expect($status===200&&count($data['data']['versions'])===1,'Scoped version history remains available');
    $expect(!str_contains(json_encode($event($cid)),'My next appointment'),'Access history excludes definition labels');
    foreach([$practitionerToken,$staffToken('widget-clinic-admin','Wellness.ClinicAdmin')] as $token)foreach([['GET',null,''],['POST',['definition'=>$definition],''],['GET',null,'/custom_next/versions'],['PATCH',['enabled'=>false],'/custom_next'],['POST',[],'/custom_next/versions/1/restore']] as [$method,$body,$suffix]){
        $before=$snapshot();[$status,$data,$cid]=$write($method,$body,$suffix,$token);$expect($status===403,'Non Super Admin denied every management route');$expect($snapshot()===$before&&$event($cid)[0]['outcome']==='denied','Role denial audited with no mutation');
    }
    foreach([['parameters'=>['client_id'=>101]],['requiredCapability'=>'appointments.view.clinic'],['workspaces'=>['admin']],['sql'=>'SELECT * FROM users'],['title'=>['en'=>'Safe','fr'=>'Safe','client_id'=>101]],['destination'=>['page'=>'appointments','client_id'=>101]],['schemaVersion'=>2],['defaultEnabled'=>'true']] as $bad)$deny(fn()=>$widgets->upload($operator,['definition'=>array_replace($definition,$bad)],'invalid'),422);
    $changed=$definition;$changed['title']['en']='Updated configuration';
    $deny(fn()=>$widgets->upload($operator,['definition'=>$changed],'duplicate'),409);
    $saved=$widgets->upload($operator,['definition'=>$changed,'confirm_replace'=>true],'widget-v2');$expect($saved['version']===2,'Confirmed replacement versions configuration');
    $widgets->restore($operator,'custom_next',1,'restore-v1');
    foreach([false,true] as $enabled){[$status]=$write('PATCH',['enabled'=>$enabled],'/custom_next');$expect($status===200,'Toggle is consent independent configuration');}
    [$status,$data,$cid]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken);
    $expect($status===200&&(int)$data['data']['values']['custom_next']['id']===$visible,'Custom next appointment preserves consent filtering and practitioner ownership');
    $expect(!in_array($hidden,array_column(json_decode($event($cid)[0]['metadata'],true)['resources'],'id'),true),'Restricted next appointment never audited as disclosed');
    [$status,$data]=$call('/api/v1/dashboard?workspace=admin',$adminToken);$expect($status===200&&!in_array('custom_next',array_column($data['data']['definitions'],'id'),true),'Publishing own capability does not grant Super Admin practitioner workspace widgets');
    // Mutation and business audit form one transaction, including toggles/restores.
    $pdo->exec("CREATE TRIGGER widget_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic widget audit unavailable'");
    foreach([fn()=>$widgets->setEnabled($operator,'custom_next',['enabled'=>false],'rollback'),fn()=>$widgets->setEnabled($operator,'awaiting_confirmation',['enabled'=>false],'rollback-insert'),fn()=>$widgets->restore($operator,'custom_next',2,'rollback'),fn()=>$widgets->upload($operator,['definition'=>$changed,'confirm_replace'=>true],'rollback')] as $op){$before=$snapshot();try{$op();throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$before,'Audit failure rolls back full widget change');}
    $pdo->exec('DROP TRIGGER widget_audit_failure');
    // Legacy tampering with a valid hash must still pass the fixed projection/capability schema.
    $unsafe=$definition;$unsafe['requiredCapability']='appointments.view.clinic';$json=json_encode($unsafe,JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE dashboard_widget_versions SET definition_json=?,definition_hash=? WHERE widget_id=? AND version_number=2')->execute([$json,hash('sha256',$json),$id]);
    $deny(fn()=>$widgets->restore($operator,'custom_next',2,'unsafe'),503);
    $pdo->exec("UPDATE dashboard_widgets SET active_version=2 WHERE id=$id");
    $deny(fn()=>$widgets->setEnabled($operator,'custom_next',['enabled'=>true],'unsafe'),503);
    $deny(fn()=>$widgets->versions($operator,'custom_next'),503);
    [$status,$data]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken);$expect($status===503&&!isset($data['data']),'Unsafe active definition cannot render patient data');
    $widgets->setEnabled($operator,'custom_next',['enabled'=>false],'repair-disable');
    [$status]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken);$expect($status===200,'Disabling unsafe widget restores dashboard availability');
    $widgets->restore($operator,'custom_next',1,'repair-restore');
    $pdo->prepare("UPDATE dashboard_widget_versions SET definition_hash=REPEAT('0',64) WHERE widget_id=? AND version_number=2")->execute([$id]);$deny(fn()=>$widgets->restore($operator,'custom_next',2,'hash'),503);
    foreach([false,true] as $enabled)$widgets->setEnabled($operator,'onsite_today',['enabled'=>$enabled],'builtin-toggle');
    $expect(count(array_filter($widgets->adminList($operator),fn($row)=>$row['id']==='onsite_today'&&$row['enabled']))===1,'Built-in first-toggle insert and reenable work with native PDO');
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",getenv('RECURRING_TEST_USER')?:'root',getenv('RECURRING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $blocking=new \Wellness\Service\ConsentLock($connection);$blocking->acquire(1);
    try{$deny(fn()=>$widgets->setEnabled($operator,'custom_next',['enabled'=>false],'busy'),503);}finally{$blocking->release();}
    $system=$built['appointments_today'];$widgets->upload($operator,['definition'=>$system,'confirm_replace'=>true],'system');$widgets->restore($operator,'appointments_today',0,'system-restore');$expect(true,'System definition restoration remains available');
    $pdo->beginTransaction();try{try{$widgets->setEnabled($operator,'custom_next',['enabled'=>false],'nested');throw new RuntimeException('Nested transaction accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Caller transaction retained and refused');}}finally{$pdo->rollBack();}
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized dashboard widgets: $checks checks passed; local synthetic database only.\n";
