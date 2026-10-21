<?php
declare(strict_types=1);
// Synthetic, disposable localhost SQL only. The shared fixture also verifies booking access.
require __DIR__.'/booking-client-access.php';
use Wellness\Service\AdminService;
use Wellness\Http\ApiException;

$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/052_service_imports.sql'));
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin';
 INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='super_admin';
 INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
 INSERT INTO user_roles(user_id,role_id,location_id) SELECT 4,id,1 FROM roles WHERE code='super_admin';
 INSERT INTO service_categories(id,clinic_id,name) VALUES(1,1,'Synthetic category'),(2,2,'Foreign category');
 UPDATE services SET category_id=1 WHERE id=1");
$admin=new AdminService($database,$audit);$checks=0;
$row=fn(string $name='Imported service',string $category='Synthetic category')=>[
 'row_number'=>2,'category'=>$category,'name'=>$name,'name_fr'=>'Service en français',
 'service_type'=>'Treatment','initial_consultation'=>false,'durations'=>[30,60],
 'public_summary'=>'Synthetic summary','public_summary_fr'=>'Résumé synthétique',
 'description'=>'Synthetic description','description_fr'=>'Description synthétique',
 'prices'=>[['minutes'=>30,'price_cents'=>0],['minutes'=>60,'price_cents'=>6500]],'service_code'=>''];
$body=fn(array $rows,array $options=[])=>array_replace(['request_key'=>sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6))),'file_name'=>'Synthetic.xlsx','rows'=>$rows,'duplicate_policy'=>'skip','create_categories'=>false],$options);
$preview=fn(array $rows,array $options=[])=>$admin->previewServiceImport($operator,$body($rows,$options),'import-preview');
$apply=fn(array $p)=>$admin->applyServiceImport($operator,['preview_id'=>$p['preview_id'],'confirmation_hash'=>$p['confirmation_hash']],'import-apply');
$snapshot=fn()=>array_map(fn($table)=>$pdo->query("SELECT * FROM $table ORDER BY 1")->fetchAll(),['services','service_categories','service_duration_options','practitioner_services','service_locations','appointments','audit_logs','service_import_batches']);
$refuse=function(callable $work,int $status,?string $code=null)use($check,$snapshot){
 $before=$snapshot();try{$work();throw new RuntimeException('Expected import refusal');}
 catch(ApiException $e){$check($e->status===$status&&($code===null||$e->errorCode===$code),"Import refusal $status/$code, received {$e->status}/{$e->errorCode}");}
 $check($snapshot()===$before&&!$GLOBALS['pdo']->inTransaction(),'Denied import is atomic and closes its transaction');
};
$valid=$preview([$row()]);
foreach([$actor(2),$actor(2,['super_admin']),$actor(3,['clinic_admin']),$actor(4,['super_admin']),$actor(6,['super_admin']),$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(99999,['super_admin'])] as $who){
 $refuse(fn()=>$admin->previewServiceImport($who,[],'denied'),403);
 $refuse(fn()=>$admin->serviceImport($who,$valid['preview_id']),403);
 $refuse(fn()=>$admin->applyServiceImport($who,[],'denied'),403);
}
foreach([fn()=>$admin->serviceImport($actor(3,['super_admin']),$valid['preview_id']),fn()=>$admin->applyServiceImport($actor(3,['super_admin']),['preview_id'=>$valid['preview_id'],'confirmation_hash'=>$valid['confirmation_hash']],'other')] as $work)$refuse($work,404,'service_import_not_found');
$foreign=$actor(5,['super_admin'],[],'staff',2);
$refuse(fn()=>$admin->serviceImport($foreign,$valid['preview_id']),404,'service_import_not_found');
$refuse(fn()=>$admin->applyServiceImport($foreign,['preview_id'=>$valid['preview_id'],'confirmation_hash'=>$valid['confirmation_hash']],'foreign'),404,'service_import_not_found');
$foreignPreview=$admin->previewServiceImport($foreign,$body([$row('Imported service','Foreign category')]),'foreign-preview');
$check($foreignPreview['can_import']&&$foreignPreview['counts']['create']===1,'A legitimate second-clinic administrator sees only its own catalogue');
$refuse(fn()=>$admin->serviceImport($operator,$foreignPreview['preview_id']),404,'service_import_not_found');
foreach([['clinic_id'=>2],['create_categories'=>'true'],['duplicate_policy'=>'replace'],['rows'=>[]],['rows'=>array_fill(0,251,$row())]] as $bad)$refuse(fn()=>$admin->previewServiceImport($operator,array_replace($body([$row()]),$bad),'invalid'),422);
$keyed=$body([$row()]);$p=$admin->previewServiceImport($operator,$keyed,'retry');$old=$snapshot();
$check($admin->previewServiceImport($operator,$keyed,'retry')['preview_id']===$p['preview_id']&&$snapshot()===$old,'Preview retry is idempotent');
$refuse(fn()=>$admin->previewServiceImport($operator,array_replace($keyed,['file_name'=>'Changed.xlsx']),'retry'),409);
foreach([
 array_replace($row(),['prices'=>[]]),array_replace($row(),['prices'=>[['minutes'=>30,'price_cents'=>true],['minutes'=>60,'price_cents'=>10]]]),
 array_replace($row(),['durations'=>[60,60]]),array_replace($row(),['durations'=>[16]]),array_replace($row(),['initial_consultation'=>'No']),
 array_replace($row(),['service_type'=>'Unknown']),array_replace($row(),['service_id'=>1]),array_replace($row(),['name'=>str_repeat('a',151)]),
 $row('Missing category','Missing category')
] as $bad){$p=$preview([$bad]);$check(!$p['can_import']&&$p['counts']['invalid']===1,'Invalid row is explained before apply');$refuse(fn()=>$apply($p),422,'service_import_invalid');}
foreach([['Café','Cafe'],['Repeat','repeat'],['One  space','One space']] as [$a,$b]){
 $p=$preview([$row($a),array_replace($row($b),['row_number'=>3])]);
 $check($p['counts']['invalid']===2,'Case, accent and collapsed-space duplicate rows cannot be applied');
}
$p=$preview([array_replace($row('One  space'),['service_code'=>'first-code']),array_replace($row('One space'),['service_code'=>'second-code','row_number'=>3])]);
$check($p['counts']['invalid']===2,'Explicit codes cannot hide whitespace-equivalent duplicate names');
$check($pdo->query("SELECT COUNT(*) FROM services WHERE name='Imported service'")->fetchColumn()===0,'Previews do not create services');
$p=$preview([$row('First imported','New category'),array_replace($row('Second imported','New category'),['row_number'=>3])],['create_categories'=>true]);
$check($p['can_import']&&$p['new_categories']===['New category'],'Preview explicitly proposes one new category');
$r=$apply($p);$check($r['counts']['create']===2&&$r['categories_created']===1,'Apply creates the reviewed rows and one shared category');
$id=(int)$r['rows'][0]['service_id'];$record=$pdo->query("SELECT * FROM services WHERE id=$id")->fetch();
$check((int)$record['active']===0&&(int)$record['published']===0&&$record['name_fr']==='Service en français','Bilingual new services are inactive and unpublished');
$prices=$pdo->query("SELECT duration_minutes,price_cents FROM service_duration_options WHERE service_id=$id ORDER BY duration_minutes")->fetchAll(PDO::FETCH_NUM);
$check($prices===[[30,0],[60,6500]],'Explicit zero and duration-specific prices are preserved');
$check((int)$pdo->query("SELECT COUNT(*) FROM practitioner_services WHERE service_id=$id")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM service_locations WHERE service_id=$id")->fetchColumn()===0,'Imports never manufacture assignments');
$check($admin->serviceImport($operator,$p['preview_id'])['rows'][0]['service_type']==='Treatment','Descriptive workbook metadata is retained in the restricted batch');
$before=$snapshot();$check($apply($p)===$r&&$snapshot()===$before,'Apply retry returns the stored result without duplicate writes or audit');
$p=$preview([$row('First imported')]);$check($p['counts']['skip']===1&&!$p['can_import'],'Existing services are skipped by default');
$pdo->exec("UPDATE services SET price_cents=7777,duration_mode='fixed',requires_room=0,lead_time_minutes=120,preparation_instructions='Keep existing preparation' WHERE id=$id;
 INSERT INTO service_duration_options(service_id,duration_minutes,price_cents,active) VALUES($id,15,999,0)");
$before=$pdo->query("SELECT * FROM services WHERE id=$id")->fetch();$options=$pdo->query("SELECT * FROM service_duration_options WHERE service_id=$id ORDER BY id")->fetchAll();
$changed=array_replace($row('Renamed service','New category'),['service_code'=>$before['slug'],'prices'=>[],'durations'=>[90]]);
$p=$preview([$changed],['duplicate_policy'=>'update_content']);$check($p['counts']['update']===1,'Stable service code matches a rename without needing prices');$apply($p);
$after=$pdo->query("SELECT * FROM services WHERE id=$id")->fetch();
$check($after['name']==='Renamed service'&&$after['slug']===$before['slug']&&$after['active']===$before['active']&&$after['published']===$before['published']&&$after['price_cents']===$before['price_cents']&&$pdo->query("SELECT * FROM service_duration_options WHERE service_id=$id ORDER BY id")->fetchAll()===$options,'Content updates preserve prices, duration IDs, state and public URL');
$check(array_diff_assoc($after,$before)===['name'=>'Renamed service'],'Content-only import preserves every other service column, including legacy base prices and booking configuration');
$p=$preview([$changed],['duplicate_policy'=>'update_content']);$check($p['counts']['skip']===1,'Unchanged content is a no-op');
$p=$preview([array_replace($row('Second imported'),['service_code'=>$before['slug']])]);$check(in_array('ambiguous_service',$p['rows'][0]['errors'],true),'Conflicting name and code matches require correction');
$p=$preview([$row('Stale')]);$pdo->exec("UPDATE services SET name='Catalogue changed' WHERE id=$id");$refuse(fn()=>$apply($p),409,'service_import_stale');
$p=$preview([$row('Expired')]);$pdo->prepare("UPDATE service_import_batches SET expires_at='2000-01-01' WHERE id=?")->execute([$p['preview_id']]);$refuse(fn()=>$apply($p),409,'service_import_expired');
$p=$preview([$row('Bad confirmation')]);$refuse(fn()=>$apply(array_replace($p,['confirmation_hash'=>str_repeat('0',64)])),409);
// A restriction on historical appointments protects service content, including on apply after preview.
$update=array_replace($row('Updated booked service'),['service_code'=>'synthetic']);
$p=$preview([$row('Must roll back','Rollback category'),array_replace($update,['row_number'=>3])],['create_categories'=>true,'duplicate_policy'=>'update_content']);
$check($p['can_import'],'Related service update is previewable before restriction');
$pdo->exec("INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,101,'staff_access',1,1,1)");
$refuse(fn()=>$apply($p),403,'consent_restricted');
$denied=$preview([$update],['duplicate_policy'=>'update_content']);$check($denied['rows'][0]['errors']===['update_not_allowed'],'Preview conceals restricted patients behind a generic row refusal');
$check($preview([$row('Unrelated new service')])['can_import'],'Unrelated restriction does not block new patient-free service creation');
$pdo->exec('DELETE FROM client_consent_directives');
$p=$preview([$row('Atomic first','Atomic category'),array_replace($row('Atomic second','Atomic category'),['row_number'=>3])],['create_categories'=>true]);
$pdo->exec("CREATE TRIGGER import_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='service_import.apply' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");
$before=$snapshot();try{$apply($p);throw new RuntimeException('Expected audit failure');}catch(PDOException){}finally{$pdo->exec('DROP TRIGGER import_audit_failure');}
$check($snapshot()===$before,'Failure after all rows rolls back categories, services, durations, audit and applied marker');$check($apply($p)['counts']['create']===2,'Same preview can be retried after a rollback');
$p=$preview([$row('Revoked')]);
foreach(["UPDATE users SET status='inactive' WHERE id=1","UPDATE clinics SET status='inactive' WHERE id=1","DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE user_id=1 AND r.code='super_admin'"] as $revoke){
 $pdo->exec($revoke);$refuse(fn()=>$apply($p),403);$refuse(fn()=>$admin->serviceImport($operator,$p['preview_id']),403);
 $pdo->exec("UPDATE users SET status='active' WHERE id=1;UPDATE clinics SET status='active' WHERE id=1;DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE user_id=1 AND r.code='super_admin';INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
}
$logs=$pdo->query("SELECT metadata FROM audit_logs WHERE action LIKE 'service_import.%'")->fetchAll(PDO::FETCH_COLUMN);
$check(count($logs)>0&&!str_contains(json_encode($logs),'Synthetic description')&&!str_contains(json_encode($logs),'price_cents'),'Import business audit stores references/counts rather than workbook content or prices');
$pdo->beginTransaction();try{try{$preview([$row()]);throw new RuntimeException('Expected nested transaction refusal');}catch(LogicException){$check($pdo->inTransaction(),'Nested call preserves the caller transaction');}}finally{$pdo->rollBack();}
echo "Service import SQL authorization: $checks checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)."; synthetic localhost only.\n";
