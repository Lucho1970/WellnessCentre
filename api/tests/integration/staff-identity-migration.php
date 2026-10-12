<?php
declare(strict_types=1);
// Local-only rehearsal of the exact standalone phpMyAdmin SQL files.
require __DIR__.'/recurring-bookings.php';
use Wellness\Auth\{AuthContext,VerifiedIdentity,StaffMembershipResolver};
use Wellness\{Config,ClinicContext};
use Wellness\Service\{StaffInvitationService,AuditLogger};
$workforce='11111111-1111-4111-8111-111111111111';
$external='22222222-2222-4222-8222-222222222222';
$issuer="https://$external.ciamlogin.com/$external/v2.0";
$pdo->exec("INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT 1,id,1 FROM roles WHERE code='super_admin';
 INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT 2,id,1 FROM roles WHERE code='practitioner'");
$pdo->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(2,'microsoft',?,'legacy-subject')")->execute([$workforce]);
$pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',?,'legacy-subject')")->execute(["https://login.microsoftonline.com/$workforce/v2.0"]);
$old=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,1,2,'active')")->execute([$old]);
$membership=(int)$pdo->lastInsertId();
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','',$workforce,'api','scope',300,
 clinicHostMap:['a.test'=>1,'b.test'=>2],staffMembershipPilotEnabled:true,staffMembershipPilotUserIds:[2],
 staffInvitationsEnabled:true,staffExternalTenantId:$external);
$service=new StaffInvitationService($database,$config,new AuditLogger($database));
$admin=new AuthContext(1,1,'','','','staff',['super_admin']);
$newIdentity=new VerifiedIdentity('entra-external-staff',$issuer,'signed-external-subject',$external);
$invite=$service->create($admin,['recipient_email'=>'synthetic@gmail.example','given_name'=>'Synthetic','family_name'=>'Practitioner','discipline'=>'Massage','location_id'=>1],'migration-test');
$claim=$service->claim(1,$newIdentity,['token'=>$invite['token'],'claimant_name'=>'Synthetic Practitioner'],'migration-test');
$params=['invitation_id'=>$invite['id'],'operator_user_id'=>1,'verification_code'=>$claim['verification_code'],
 'expected_staff_tenant'=>$external,'expected_workforce_tenant'=>$workforce,'membership_id'=>$membership,
 'membership_version'=>1,'old_identity_id'=>$old,'new_identity_id'=>0,'confirmation'=>'','pilot_configuration_checked'=>1];
$run=function(string $file,array $values=[])use($pdo): array {
 $sql=file_get_contents(dirname(__DIR__,2).'/maintenance/esther-identity/'.$file.'.sql');
 foreach($values as $key=>$value){$literal=is_int($value)?(string)$value:$pdo->quote($value);
  $sql=preg_replace('/SET @'.preg_quote($key,'/').' = [^;]*;/','SET @'.$key.' = '.$literal.';',$sql);}
 $sql=preg_replace('/^--.*$/m','',$sql);$results=[];
 foreach(explode(';',$sql) as $part){if(trim($part)==='')continue;$stmt=$pdo->query($part);if($stmt->columnCount())$results[]=$stmt->fetchAll();$stmt->closeCursor();}
 return $results;
};
$checks=0;$check=function(bool $ok,string $message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$row=fn()=>$pdo->query('SELECT id,identity_id,clinic_id,local_user_id,status,version FROM staff_memberships WHERE local_user_id=2')->fetch();
$original=$row();
$preserved=function()use($pdo): string {
 $tables=['users','practitioners','identity_links','user_roles','user_permissions','appointments','recurring_series','practitioner_locations','practitioner_services'];$rows=[];
 foreach($tables as $table)$rows[$table]=$pdo->query("SELECT * FROM $table ORDER BY 1")->fetchAll();
 return json_encode($rows,JSON_THROW_ON_ERROR);
};
$before=$preserved();
$run('01-stage-identity');$run('02-apply');$run('03-rollback');
$check($row()===$original,'Placeholder scripts must not change membership');
$check((int)$pdo->query('SELECT COUNT(*) FROM product_identities')->fetchColumn()===1,'Placeholders must not create identities');
$review=$run('00-review',['invitation_id'=>$invite['id']]);
$check((int)$review[0][0]['old_identity_id']===$old,'Read-only report identifies original binding');
$stage=array_replace($params,['confirmation'=>'STAGE USER 2 IDENTITY']);
$run('01-stage-identity',array_replace($stage,['verification_code'=>'000000000000']));
$check((int)$pdo->query('SELECT COUNT(*) FROM product_identities')->fetchColumn()===1,'Unverified claim must not stage identity');
$run('01-stage-identity',$stage);$new=(int)$pdo->query("SELECT id FROM product_identities WHERE adapter='entra-external-staff' AND subject='signed-external-subject'")->fetchColumn();
$check($new>0 && $new!==$old && $row()===$original,'Staging must leave login binding unchanged');
$run('01-stage-identity',$stage);
$check((int)$pdo->query('SELECT COUNT(*) FROM product_identities')->fetchColumn()===2,'Staging is idempotent');
$apply=array_replace($params,['new_identity_id'=>$new,'confirmation'=>'MIGRATE USER 2']);
foreach(['membership_version'=>99,'verification_code'=>'000000000000','membership_id'=>999,'old_identity_id'=>999,
 'new_identity_id'=>999,'operator_user_id'=>2,'expected_staff_tenant'=>$workforce,'expected_workforce_tenant'=>$external,
 'confirmation'=>'','pilot_configuration_checked'=>0] as $key=>$value){
 $run('02-apply',array_replace($apply,[$key=>$value]));$check($row()===$original,'Rejected parameter '.$key.' must preserve binding');
}
foreach([
 ["UPDATE staff_memberships SET status='revoked' WHERE id=$membership","UPDATE staff_memberships SET status='active' WHERE id=$membership"],
 ["UPDATE product_identities SET status='inactive' WHERE id=$new","UPDATE product_identities SET status='active' WHERE id=$new"],
 ["UPDATE users SET status='inactive' WHERE id=1","UPDATE users SET status='active' WHERE id=1"],
 ["UPDATE practitioners SET active=0 WHERE user_id=2","UPDATE practitioners SET active=1 WHERE user_id=2"],
 ["UPDATE staff_invitations SET revoked_at=UTC_TIMESTAMP() WHERE id={$invite['id']}","UPDATE staff_invitations SET revoked_at=NULL WHERE id={$invite['id']}"],
 ["UPDATE staff_invitations SET expires_at='2000-01-01' WHERE id={$invite['id']}","UPDATE staff_invitations SET expires_at=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE id={$invite['id']}"],
 ["INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT 2,id,1 FROM roles WHERE code='super_admin'","DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=2 AND r.code='super_admin'"],
] as [$change,$restore]){$pdo->exec($change);$state=$row();$run('02-apply',$apply);$check($row()===$state,'Changed eligibility must fail closed');$pdo->exec($restore);}
$pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,1,9,'revoked')")->execute([$new]);
$run('02-apply',$apply);$check($row()===$original,'Existing target binding is never reused or reactivated');
$pdo->exec('DELETE FROM staff_memberships WHERE local_user_id=9');
// Eligibility tests intentionally changed timestamps; snapshot restored source immediately before migration.
$before=$preserved();
$run('02-apply',$apply);$migrated=$row();
$check((int)$migrated['identity_id']===$new && (int)$migrated['version']===2,'Reviewed migration preserves membership ID and increments version');
$check((int)$migrated['id']===$membership && (int)$migrated['local_user_id']===2,'Existing local binding preserved');
$check($before===$preserved(),'User, practitioner, links, roles, permissions and appointments unchanged');
$check($pdo->query('SELECT status FROM staff_invitation_claims')->fetchColumn()==='approved','Signed claim approved atomically');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='staff.identity.migrate'")->fetchColumn()===1,'Successful migration audited');
$run('02-apply',$apply);$check($row()===$migrated,'Apply replay cannot repeat rebinding');
$resolver=new StaffMembershipResolver($database);$clinic=ClinicContext::forHost($config,'a.test');
$check($resolver->resolve($newIdentity,$clinic)->userId===2,'External staff resolves to the existing user');
$oldIdentity=new VerifiedIdentity('entra-workforce',"https://login.microsoftonline.com/$workforce/v2.0",'legacy-subject',$workforce,['practitioner']);
try{$resolver->resolve($oldIdentity,$clinic);throw new RuntimeException('Old membership should fail');}catch(\Wellness\Http\ApiException $e){$check($e->errorCode==='membership_required','Old membership denied');}
$rollback=array_replace($apply,['confirmation'=>'ROLLBACK USER 2','membership_version'=>2]);
$run('03-rollback',array_replace($rollback,['membership_version'=>1]));$check($row()===$migrated,'Stale rollback denied');
$run('03-rollback',$rollback);$restored=$row();
$check((int)$restored['identity_id']===$old && (int)$restored['version']===3,'Rollback restores original identity and increments version');
$check($resolver->resolve($oldIdentity,$clinic)->userId===2,'Original practitioner sign-in restored');
try{$resolver->resolve($newIdentity,$clinic);throw new RuntimeException('External membership should fail after rollback');}catch(\Wellness\Http\ApiException $e){$check($e->errorCode==='membership_required','External binding denied after rollback');}
$check($before===$preserved(),'Rollback preserves existing practitioner data');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='staff.identity.rollback'")->fetchColumn()===1,'Rollback audited');
$run('03-rollback',$rollback);$check($row()===$restored,'Rollback replay cannot repeat changes');
$check(!$pdo->inTransaction(),'Scripts close all transactions');
echo "Esther standalone SQL migration: $checks checks passed.\n";
