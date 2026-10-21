<?php
declare(strict_types=1);
// Disposable local SQL and authenticated HTTP only; no hosted patient records.
require __DIR__.'/access-audit.php';
use Wellness\Service\{SessionReceiptService,ClientConsentService,ConsentLock};
use Wellness\Http\ApiException;
$checks=0;$receipts=new SessionReceiptService($database,$audit);$policy=new ClientConsentService($database,$audit);
$template=['version'=>0,'issuer_name'=>'Synthetic clinic issuer','issuer_address'=>'123 Synthetic Street','issuer_contact'=>'issuer@example.test','clinic_logo'=>''];
$snapshot=fn()=>[$pdo->query('SELECT * FROM receipt_templates ORDER BY clinic_id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$deny=function(callable $work,int $status=403,?string $code=null)use($expect,$snapshot):void{
    $before=$snapshot();try{$work();throw new RuntimeException('Expected receipt-template refusal');}
    catch(ApiException $e){$expect($e->status===$status&&($code===null||$e->errorCode===$code),'Expected receipt-template refusal '.$status.'/'.$code.' got '.$e->status.'/'.$e->errorCode);}
    $expect($snapshot()===$before,'Refusal preserves all clinic templates and business audits');
};
$_ENV['SESSION_RECEIPTS_ENABLED']='false';$deny(fn()=>$receipts->saveTemplate($operator,$template,'disabled'),503,'receipts_disabled');
$_ENV['SESSION_RECEIPTS_ENABLED']='true';
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 6,id FROM roles WHERE code='super_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 101,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','template-admin');");
$expect($receipts->saveTemplate($operator,$template,'initial-template')===['clinic_id'=>1,'version'=>1],'Current Super Admin creates a versioned issuer template');
// Create a synthetic issued receipt before imposing restrictions, to prove immutable history.
$appointment=(int)$pdo->query('SELECT id FROM appointments WHERE client_id=101 LIMIT 1')->fetchColumn();
$pdo->exec("UPDATE appointments SET status='completed',version=1,duration_option_id=1,base_price_cents=10000,mobile_fee_cents=0 WHERE id=$appointment");
$receipts->saveProfile($practitioner,$appointment,['version'=>0,'practitioner_name'=>'Synthetic practitioner','designation'=>'Test designation','registration_number'=>'TEST'],'template-fixture');
$receipt=$receipts->issue($practitioner,$appointment,['version'=>1,'template_version'=>1,'profile_version'=>1,'tax_cents'=>0,'amount_cents'=>10000,'method'=>'cash','paid_on'=>'2026-01-02','confirmed'=>true,'idempotency_key'=>'synthetic-template-receipt'],'template-fixture');$receiptId=$receipt['id'];
foreach([0,1,2] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'template-policy');
$patients=fn()=>array_map(fn($table)=>$pdo->query("SELECT * FROM $table".($table==='users'?" WHERE user_type='client'":'')." ORDER BY 1")->fetchAll(),['users','appointments','client_consent_directives','practitioner_receipt_profiles','session_receipts','invoices','payments','notification_events']);
$patientBefore=$patients();
foreach([$actor(2),$actor(2,['super_admin']),$actor(3,['practitioner']),$actor(3,['reception']),$actor(3,['accountant']),$actor(3,[],['review_privacy_audit']),$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin']),$actor(99999,['super_admin'])] as $bad)$deny(fn()=>$receipts->saveTemplate($bad,array_replace($template,['version'=>1]),'denied'));
$pdo->exec("UPDATE users SET status='inactive' WHERE id=1");$deny(fn()=>$receipts->saveTemplate($operator,$template,'inactive'));$pdo->exec("UPDATE users SET status='active' WHERE id=1");
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");$deny(fn()=>$receipts->saveTemplate($operator,$template,'revoked'));$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
$pdo->exec("UPDATE users SET clinic_id=2 WHERE id=1");$deny(fn()=>$receipts->saveTemplate($operator,$template,'moved'));$pdo->exec("UPDATE users SET clinic_id=1 WHERE id=1");
$pdo->exec("UPDATE clinics SET status='inactive' WHERE id=1");$deny(fn()=>$receipts->saveTemplate($operator,$template,'inactive-clinic'));$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
foreach([['version'=>-1],['version'=>'1'],['version'=>null],['issuer_name'=>[]],['issuer_address'=>str_repeat('x',301)],['issuer_contact'=>"bad\ncontact"],['clinic_logo'=>'data:text/html;base64,PHNjcmlwdD4=']] as $bad)$deny(fn()=>$receipts->saveTemplate($operator,array_replace($template,$bad),'invalid'),422,'validation_error');
$deny(fn()=>$receipts->saveTemplate($operator,$template,'stale'),409,'receipt_settings_changed');
$deny(fn()=>(new \Wellness\Service\ConsentAccess($pdo))->request($operator,new \Wellness\Http\Request('POST','/api/v1/unclassified-operation',[],[],[],'unknown-route'),'unknownRoute'),403,'consent_scope_restricted');
$env['ACCESS_AUDIT_TEST_RECEIPTS']='synthetic-local-only';
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/receipt-templates-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/receipt-templates-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start receipt-template API');fclose($pipes[0]);
try{
    for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $current=array_replace($template,['version'=>1,'issuer_name'=>'Changed future issuer','clinic_id'=>2,'client_id'=>101,'appointment_id'=>$appointment]);
    [$status,$data,$cid]=$call('/api/v1/receipts/template',$adminToken,'PUT',$current);
    $expect($status===200&&$data['data']===['clinic_id'=>1,'version'=>2],'Current admin saves own clinic template despite global and targeted patient restrictions');
    $row=$event($cid)[0];$meta=json_decode($row['metadata'],true);
    $expect($row['outcome']==='success'&&$meta['resources']===[['type'=>'clinic','id'=>1]],'Central audit references only the authorized clinic');
    $expect(!str_contains(json_encode($row),'Changed future issuer')&&!str_contains(json_encode($row),'issuer@example.test'),'Access audit excludes issuer text, contacts and logo');
    $stored=json_decode($pdo->query('SELECT settings FROM receipt_templates WHERE clinic_id=1')->fetchColumn(),true);
    $expect(array_keys($stored)===['issuer_name','issuer_address','issuer_contact','clinic_logo']&&(int)$pdo->query('SELECT COUNT(*) FROM receipt_templates WHERE clinic_id=2')->fetchColumn()===0,'Submitted IDs cannot redirect or attach template settings to a patient or foreign clinic');
    $before=$snapshot();[$status,$data,$cid]=$call('/api/v1/receipts/template',$practitionerToken,'PUT',array_replace($template,['version'=>2]));
    $expect($status===403&&$data['error']['code']==='forbidden'&&$snapshot()===$before&&$event($cid)[0]['outcome']==='denied','Practitioner template write is denied and audited without changing settings');
    [$status]=$call('/api/v1/receipts/template',$adminToken,'PUT',array_replace($template,['version'=>2]),'audit-b.test');$expect($status===403&&$snapshot()===$before,'Host switching cannot transfer template authority');
    foreach([$adminToken,$practitionerToken] as $token){
        foreach([["/api/v1/appointments/$appointment/receipt",'GET',null],["/api/v1/appointments/$appointment/receipt-profile",'PUT',['version'=>1,'practitioner_name'=>'Attempted edit','designation'=>'Test']],["/api/v1/appointments/$appointment/receipt",'POST',[]],["/api/v1/receipts/$receiptId/pdf",'GET',null],["/api/v1/receipts/$receiptId/void",'POST',[]]] as [$path,$method,$body]){
            [$status,$data,$cid,$raw]=$call($path,$token,$method,$body);
            $expect($status===403&&$data['error']['code']==='consent_restricted'&&$event($cid)[0]['outcome']==='denied','Patient-scoped receipt reads and writes remain restricted after template exemption');
            $expect(!str_contains($raw,'Synthetic Appointment')&&!str_contains($raw,'pdf_base64'),'Denied receipt response contains no patient or PDF');
        }
    }
    [$status,$data]=$call('/api/v1/receipts/template',$staffToken('template-admin','Wellness.ClinicAdmin'),'PUT',array_replace($template,['version'=>2]));$expect($status===200&&$data['data']['version']===3,'Current Clinic Admin may maintain future issuer settings');
    [$status,$data]=$call('/api/v1/receipts/template',$adminToken,'PUT',array_replace($template,['version'=>2]));$expect($status===409&&$data['error']['code']==='receipt_settings_changed','Stale writer cannot overwrite a newer clinic template');
    $pdo->exec("CREATE TRIGGER template_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic template audit unavailable'");
    try{$before=$snapshot();try{$receipts->saveTemplate($operator,array_replace($template,['version'=>3]),'rollback');throw new RuntimeException('Expected audit failure');}catch(PDOException $e){}$expect($snapshot()===$before,'Business audit failure rolls back template contents, version and updater');}finally{$pdo->exec('DROP TRIGGER template_audit_failure');}
    $expect($patients()===$patientBefore,'Template changes preserve issued PDFs/snapshots, payments, profiles, bookings, users, notices and consent instructions');
    // Independent authorized clinic administration uses its own template version and lock.
    $result=$receipts->saveTemplate($actor(5,['super_admin'],[],'staff',2),$template,'foreign-own-clinic');$expect($result===['clinic_id'=>2,'version'=>1]&&(int)$pdo->query('SELECT version FROM receipt_templates WHERE clinic_id=1')->fetchColumn()===3,'Clinic B authority changes only clinic B configuration');
    $connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);
    try{$deny(fn()=>$receipts->saveTemplate($operator,array_replace($template,['version'=>3]),'busy'),503,'consent_busy');}finally{$blocking->release();}
    $pdo->beginTransaction();try{try{$receipts->saveTemplate($operator,array_replace($template,['version'=>3]),'nested');throw new RuntimeException('Nested template operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Nested refusal preserves caller transaction');}}finally{$pdo->rollBack();}
    $expect($receipts->saveTemplate($operator,array_replace($template,['version'=>3]),'after-busy')['version']===4,'Failed operations release their locks and leave the next valid save usable');
}finally{proc_terminate($server);proc_close($server);}
echo "Consent-authorized receipt templates: $checks checks passed; local synthetic SQL/HTTP only.\n";
