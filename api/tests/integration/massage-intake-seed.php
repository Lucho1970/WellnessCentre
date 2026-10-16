<?php
declare(strict_types=1);
// Real SQL against the synthetic localhost fixture only; never hosted data.
require __DIR__.'/form-library.php';
use Wellness\Service\ClientFormDefinition;
$_ENV['FORM_LIBRARY_ENABLED']='true';
$checks=0;
$root=dirname(__DIR__,3);
$document=json_decode(file_get_contents($root.'/api/database/templates/massage-therapy-intake.json'),true,32,JSON_THROW_ON_ERROR);
$validated=ClientFormDefinition::definition($document['definition'],'intake');
$assert(count($validated['questions'])===20&&count($validated['sections'])===4,'Starter has expected sections and questions');
$assert(!array_filter($validated['questions'],fn($q)=>$q['required']||$q['label_fr']===''),'Private discussion supported and French labels present');
$import=$draftService->import($clinician,['document'=>$document,'owner_practitioner_id'=>3,'idempotency_key'=>'massage-json-import'],'seed-test');
$assert($draftService->detail($clinician,$import['id'],'seed-test')['payload']['definition']===$validated,'JSON imports through the actual private draft workflow');
$source=file_get_contents($root.'/api/database/templates/seed-massage-therapy-intake.sql');
$runSeed=function(?int $adminId=null)use($pdo,$source):array {
    $sql=$source;
    if ($adminId!==null) $sql=preg_replace('/^SET @seed_admin_id = .*;$/m','SET @seed_admin_id = '.$adminId.';',$sql,1);
    $result=[];
    foreach(explode(';',preg_replace('/^--.*$/m','',$sql)) as $statement) {
        if(trim($statement)==='')continue;
        $query=$pdo->query($statement);
        if($query->columnCount()>0) $result=$query->fetchAll();
        $query->closeCursor();
    }
    return $result[0];
};
$before=$pdo->query('SELECT COUNT(*) FROM form_library_entries')->fetchColumn();
$assert(str_starts_with($runSeed(999999)['result'],'STOP'),'Unknown administrator produces explicit stop');
$assert($pdo->query('SELECT COUNT(*) FROM form_library_entries')->fetchColumn()===$before,'Invalid administrator writes nothing');
$created=$runSeed();$id=(int)$created['initial_version_id'];$entry=(int)$created['entry_id'];
$assert(str_starts_with($created['result'],'CREATED'),'Unambiguous active administrator creates starter');
$detail=$library->detail($platform,$id);
$assert($detail['status']==='pending'&&$detail['can_review'],'Seed enters global approval workflow');
$assert($detail['definition']===$validated,'SQL preserves full validated bilingual definition');
$assert($detail['name']===$document['name'],'Accented template name round trips');
$deny(fn()=>$library->copy($clinician,$id,['owner_practitioner_id'=>3,'idempotency_key'=>'pending-seed-copy'],'seed-test'),'library_not_found');
$assert(str_starts_with($runSeed()['result'],'UNCHANGED'),'Pending retry does not duplicate');
$assert((int)$pdo->query("SELECT COUNT(*) FROM form_library_events WHERE version_id=$id")->fetchColumn()===1,'Single seed submission event');
$library->review($platform,$id,['action'=>'approve','revision'=>1],'seed-test');
$assert(str_starts_with($runSeed()['result'],'UNCHANGED'),'Approved retry preserved');
$copy=$library->copy($clinician,$id,['owner_practitioner_id'=>3,'idempotency_key'=>'approved-seed-copy'],'seed-test');
$copyPayload=$draftService->detail($clinician,$copy['id'],'seed-test')['payload'];
$assert($copyPayload['definition']===$validated&&$copyPayload['service_ids']===[],'Approved starter copies into independent unassigned draft');
$revised=$library->submit($platform,['scope'=>'global','entry_id'=>$entry,'expected_version'=>1,'name'=>'Reviewed massage starter','description'=>'Revised by reviewer','form_type'=>'intake','definition'=>$validated,'idempotency_key'=>'seed-revision-test'],'seed-test');
$runSeed();
$assert($library->detail($platform,$revised['id'])['name']==='Reviewed massage starter','Seed retry preserves later revision');
$library->review($platform,$revised['id'],['action'=>'approve','revision'=>1],'seed-test');
$library->review($platform,$revised['id'],['action'=>'retire','revision'=>2],'seed-test');
$runSeed();
$assert((int)$pdo->query("SELECT archived FROM form_library_entries WHERE id=$entry")->fetchColumn()===1,'Seed never restores retirement');
$assert((int)$pdo->query("SELECT COUNT(*) FROM form_library_versions WHERE entry_id=$entry")->fetchColumn()===2,'No extra seed revisions');
$pdo->exec("INSERT INTO application_administrators(issuer,subject,display_name,email) VALUES('https://synthetic.test','second-admin','Second','second@example.test')");
$assert(str_starts_with($runSeed()['result'],'STOP'),'Ambiguous administrators require explicit selection');
$assert(str_starts_with($runSeed(1)['result'],'UNCHANGED'),'Explicit active administrator supported');
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
$assert(str_starts_with($runSeed(1)['result'],'STOP'),'Revoked administrator rejected');
$assert(!$pdo->inTransaction(),'Seed closes transaction');
echo "Massage starter real SQL acceptance: $checks checks passed.\n";
