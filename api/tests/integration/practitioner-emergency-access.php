<?php
declare(strict_types=1);
// Runs only against a disposable synthetic MariaDB instance.
require __DIR__.'/booking-client-access.php';

use Wellness\Service\AdminService;
use Wellness\Http\ApiException;

$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 4,id FROM roles WHERE code='clinic_admin'");
$admin=new AdminService($database,$audit);
$clinicAdmin=$actor(4,['clinic_admin']);
$assert=function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=function(callable $action,int $status)use($assert):void{
    try{$action();throw new RuntimeException('Expected denial');}
    catch(ApiException $e){$assert($e->status===$status,'Unexpected denial status: '.$e->status);}
};
$before=(int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
$list=$admin->emergencyPractitioners($clinicAdmin);
$assert(in_array(2,array_map('intval',array_column($list,'practitioner_id')),true),'Clinic admin sees local practitioner');
$assert(!in_array(5,array_map('intval',array_column($list,'practitioner_id')),true),'Foreign practitioner concealed');
$denied(fn()=>$admin->emergencyPractitioners($actor(3,['reception'])),403);
$pdo->exec("UPDATE users SET status='active' WHERE id=6;
    INSERT INTO user_roles(user_id,role_id) SELECT 6,id FROM roles WHERE code='reception'");
$officeBody=['display_name'=>'Synthetic Office','email'=>'inactive-staff@example.test','status'=>'active','roles'=>['reception'],'permissions'=>['hold_practitioner_access']];
$admin->updateStaff($operator,6,$officeBody,'grant-office-hold');
$assert((int)$pdo->query("SELECT COUNT(*) FROM user_permissions up JOIN permissions perm ON perm.id=up.permission_id WHERE up.user_id=6 AND perm.code='hold_practitioner_access'")->fetchColumn()===1,'Super Admin grants narrow office permission');
$office=$actor(6,['reception'],['hold_practitioner_access']);
$assert(in_array(2,array_map('intval',array_column($admin->emergencyPractitioners($office),'practitioner_id')),true),'Explicit office permission can view local hold controls');
$denied(fn()=>$admin->emergencyPractitionerAccess($actor(6,['reception']),2,['hold'=>true,'reason_code'=>'security_concern'],'missing-permission-claim'),403);
$denied(fn()=>$admin->emergencyPractitionerAccess($clinicAdmin,5,['hold'=>true,'reason_code'=>'security_concern'],'foreign'),404);
$denied(fn()=>$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>'yes','reason_code'=>'security_concern'],'bad'),422);
$denied(fn()=>$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>true,'reason_code'=>'patient-name'],'bad'),422);
$result=$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>true,'reason_code'=>'security_concern'],'hold-1');
$assert($result['status']==='locked'&&(int)$pdo->query('SELECT COUNT(*) FROM practitioner_emergency_holds WHERE user_id=2 AND active=1')->fetchColumn()===1,'Clinic admin holds access');
$assert($pdo->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='locked','Existing token will fail active-user check');
$assert((int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===$before,'Hold preserves bookings');
$denied(fn()=>$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>true,'reason_code'=>'other'],'duplicate'),409);
$denied(fn()=>$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>false],'restore-denied'),403);
$denied(fn()=>$admin->emergencyPractitionerAccess($office,2,['hold'=>false],'office-restore-denied'),403);
$update=['given_name'=>'Synthetic','family_name'=>'Practitioner','display_name'=>'Synthetic Practitioner','email'=>'practitioner@example.test','location_id'=>1,'discipline'=>'Massage','booking_mode'=>'clinic_managed','status'=>'active','active'=>true];
$denied(fn()=>$admin->updatePractitioner($operator,2,$update,'bypass-denied'),409);
$denied(fn()=>$admin->updatePractitioner($operator,2,array_replace($update,['status'=>'inactive']),'inactive-while-held'),409);
$staff=$pdo->query('SELECT display_name,email FROM users WHERE id=2')->fetch();
$staffUpdate=['display_name'=>$staff['display_name'],'email'=>$staff['email'],'status'=>'inactive','roles'=>['practitioner'],'permissions'=>[]];
$denied(fn()=>$admin->updateStaff($operator,2,$staffUpdate,'staff-inactive-while-held'),409);
$denied(fn()=>$admin->updateStaff($operator,2,array_replace($staffUpdate,['status'=>'locked','roles'=>[]]),'staff-role-removal-while-held'),409);
$assert($pdo->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='locked'
    &&(int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=2 AND r.code='practitioner'")->fetchColumn()===1,
    'Ordinary edits cannot strand an active emergency hold');
$result=$admin->emergencyPractitionerAccess($operator,2,['hold'=>false],'restore-1');
$assert($result['status']==='active'&&(int)$pdo->query('SELECT active FROM practitioner_emergency_holds WHERE user_id=2')->fetchColumn()===0,'Super Admin restores access');
$denied(fn()=>$admin->emergencyPractitionerAccess($operator,2,['hold'=>false],'duplicate-release'),409);
$officeHold=$admin->emergencyPractitionerAccess($office,2,['hold'=>true,'reason_code'=>'security_concern'],'office-hold');
$assert($officeHold['status']==='locked','Explicit office permission can stop access');
$admin->emergencyPractitionerAccess($operator,2,['hold'=>false],'office-hold-release');
$officeBody['permissions']=[];$admin->updateStaff($operator,6,$officeBody,'revoke-office-hold');
$denied(fn()=>$admin->emergencyPractitioners($office),403);
$pdo->exec("CREATE TRIGGER emergency_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try{$admin->emergencyPractitionerAccess($clinicAdmin,2,['hold'=>true,'reason_code'=>'departure_review'],'audit-fail');throw new RuntimeException('Audit failure accepted');}
catch(PDOException $e){}finally{$pdo->exec('DROP TRIGGER emergency_audit_failure');}
$assert($pdo->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='active'&&(int)$pdo->query('SELECT active FROM practitioner_emergency_holds WHERE user_id=2')->fetchColumn()===0,'Audit failure rolls back hold');
echo "Emergency practitioner access: $checks checks passed; disposable synthetic SQL only.\n";
