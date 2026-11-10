<?php
declare(strict_types=1);
require dirname(__DIR__).'/helpers/server-session-fixture.php';
use Wellness\{Database,SessionConnection};
use Wellness\Auth\{AuthContext,ApplicationAdminContext,VerifiedIdentity};
use Wellness\Service\{AuditLogger,CommitSafeEmail,StaffNotificationPreferences,CustomerOnboarding,PractitionerEnquiryService,GraphMailClient};
if (getenv('RECURRING_TEST_ALLOW_CREATE')!=='true') throw new RuntimeException('Explicit disposable database required.');
$port=(int)getenv('RECURRING_TEST_PORT');if ($port<1024 || $port>65535) throw new RuntimeException('Invalid disposable port.');
$root=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('RECURRING_TEST_USER')?:'root',getenv('RECURRING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_session_integration_'.bin2hex(random_bytes(6));$root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$root->exec("USE `$name`");
ServerSessionFixture::seed($root);$root->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/061_commit_safe_email.sql'));
ServerSessionFixture::pilot();
putenv('COMMIT_SAFE_EMAIL_ENABLED=true');$key=bin2hex(random_bytes(32));putenv('COMMIT_SAFE_EMAIL_KEY='.$key);
putenv('MAIL_ENABLED=true');putenv('MAIL_FROM_ADDRESS=sender@example.test');
putenv('MAIL_TENANT_ID=11111111-1111-4111-8111-111111111111');putenv('MAIL_CLIENT_ID=22222222-2222-4222-8222-222222222222');
putenv('PRACTITIONER_PILOT_ACCESS_ENABLED=true');
$allow='["sender@example.test","personal@example.test","fresh@example.test","inbox@example.test"]';putenv('PILOT_ALLOWED_EMAILS='.$allow);
$config=ServerSessionFixture::config($port,$name);$database=new Database($config);$db=$database->connection();
$actor=new AuthContext(1,1,ServerSessionFixture::oid('workforce'),'admin@example.test','Synthetic Admin','staff',['super_admin']);
$admin=new ApplicationAdminContext(1,new VerifiedIdentity('entra-workforce',ServerSessionFixture::policy('workforce')->issuer(),ServerSessionFixture::oid('workforce'),ServerSessionFixture::policy('workforce')->tenant),'Synthetic Admin','admin@example.test');
$prefs=new StaffNotificationPreferences($database,new AuditLogger($database));$outbox=new CommitSafeEmail($db);
$root->exec("INSERT INTO staff_notification_preferences(user_id,personal_email) VALUES(1,'personal@example.test'); INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(7,1,'fresh@example.test','Synthetic Fresh','client','active'); INSERT INTO client_profiles(user_id) VALUES(7);
 INSERT INTO practitioner_enquiry_settings(clinic_id,recipient_email) VALUES(1,'inbox@example.test');
 INSERT INTO practitioner_enquiries(id,clinic_id,reference,request_hash,full_name,email,discipline,service_area,message) VALUES(1,1,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',REPEAT('a',64),'Synthetic Applicant','applicant@example.test','Synthetic','Synthetic','Never disclose this private recruitment body')");
$sent=[];$requests=0;$mode='accepted';$during=null;
$mailer=new GraphMailClient('11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222','synthetic-not-a-credential','sender@example.test',function($url,$headers,$body,$mail) use(&$sent,&$requests,&$mode,&$during,$root) {
    $requests++;
    if (!$mail) return [200,0,json_encode(['access_token'=>'synthetic-only','expires_in'=>3600])];
    SessionTest::check((int)$root->query("SELECT COUNT(*) FROM commit_safe_emails WHERE status='sending'")->fetchColumn()>=1,'Claim is physically committed before provider call');
    SessionTest::check((int)$root->query("SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE '%submission_intent%'")->fetchColumn()>=1,'Required intent audit committed before provider call');
    if ($during) $during();
    $sent[]=json_decode($body,true,flags:JSON_THROW_ON_ERROR)['message'];
    if ($mode==='unknown') throw new RuntimeException('Synthetic uncertain provider result');
    return [$mode==='accepted'?202:(int)$mode,0,''];
});
$queueCode=function() use($root,$prefs,$actor): void {
    $root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL,personal_email_verified_at=NULL,personal_email=\'personal@example.test\' WHERE user_id=1');
    $result=$prefs->sendVerification($actor,'synthetic-email-test');SessionTest::check($result===['queued'=>true,'sent'=>false],'Verification truthfully queued');
};
$last=function() use($root): array {return $root->query('SELECT * FROM commit_safe_emails ORDER BY id DESC LIMIT 1')->fetch();};
$checkTerminal=function(string $state) use($last): void { $row=$last();SessionTest::check($row['status']===$state,'Expected terminal '.$state.', got '.$row['status']);SessionTest::check($row['ciphertext']===null,'Terminal short-lived secret erased'); };

// A service commit is only a savepoint while the request owns its final audit/commit.
$db->beginRequest();$db->checkpointRequest();$result=$prefs->sendVerification($actor,'synthetic-rollback');
SessionTest::check((int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn()===0,'Uncommitted outbox invisible on independent connection');
try {$outbox->run($mailer);throw new RuntimeException('Worker escaped hidden root');} catch (LogicException) {SessionTest::check(true,'Worker refuses hidden originating request');}
$db->rollbackEffects();$db->commitRequest();
SessionTest::check($outbox->run($mailer)['accepted']===0 && $requests===0,'Rolled-back request never reaches provider');
SessionTest::check($root->query('SELECT email_code_hash FROM staff_notification_preferences WHERE user_id=1')->fetchColumn()===null,'Code state rolls back with outbox');
$db->beginTransaction();try {$outbox->run($mailer);throw new RuntimeException('Worker escaped ordinary transaction');} catch (LogicException) {SessionTest::check(true,'Worker refuses ordinary transaction');}$db->rollBack();

$queueCode();$queued=$last();SessionTest::check($queued['ciphertext']!==null && !str_contains($queued['ciphertext'],'personal@example.test'),'Queue does not store plaintext destination');
$outbox->run($mailer);$checkTerminal('accepted');
$message=end($sent);preg_match('/code is (\d{8})/',$message['body']['content'],$code);
SessionTest::check(isset($code[1]) && password_verify($code[1],$root->query('SELECT email_code_hash FROM staff_notification_preferences WHERE user_id=1')->fetchColumn()),'Actual delivered verification code matches saved hash');
SessionTest::check(str_contains($message['body']['content'],'Votre code'),'Bilingual verification content preserved');
$before=count($sent);$outbox->run($mailer);SessionTest::check(count($sent)===$before,'Accepted job never resubmitted');
SessionTest::deny(fn()=>$prefs->sendVerification($actor,'rate-test'),429,'Existing verification throttle retained');

// Revocation and restoration must not revive a queued authority snapshot.
$queueCode();$root->exec("UPDATE users SET status='inactive' WHERE id=1; UPDATE users SET status='active' WHERE id=1");$outbox->run($mailer);$checkTerminal('canceled');
$queueCode();$root->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'; INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");$outbox->run($mailer);$checkTerminal('canceled');
$queueCode();$root->exec("UPDATE staff_notification_preferences SET personal_email='changed@example.test' WHERE user_id=1");$outbox->run($mailer);$checkTerminal('canceled');
$queueCode();$root->exec('UPDATE staff_notification_preferences SET email_code_attempts=5 WHERE user_id=1');$outbox->run($mailer);$checkTerminal('canceled');
$queueCode();$root->exec("UPDATE commit_safe_emails SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE status='queued'");$outbox->run($mailer);$checkTerminal('needs_review'); // AAD binds immutable expiry, so direct tampering is detected.
$queueCode();$root->exec("UPDATE staff_notification_preferences SET email_code_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE user_id=1");$outbox->run($mailer);$checkTerminal('canceled');
$queueCode();putenv('MAIL_FROM_ADDRESS=changed@example.test');$outbox->run($mailer);$checkTerminal('canceled');putenv('MAIL_FROM_ADDRESS=sender@example.test');
$queueCode();putenv('PILOT_ALLOWED_IDENTITIES=[]');$outbox->run($mailer);$checkTerminal('canceled');ServerSessionFixture::pilot();putenv('MAIL_ENABLED=true');putenv('PILOT_ALLOWED_EMAILS='.$allow);
$queueCode();putenv('PILOT_ALLOWED_EMAILS=[]');$outbox->run($mailer);$checkTerminal('canceled');putenv('PILOT_ALLOWED_EMAILS='.$allow);
$queueCode();putenv('COMMIT_SAFE_EMAIL_KEY='.bin2hex(random_bytes(32)));$outbox->run($mailer);$checkTerminal('needs_review');putenv('COMMIT_SAFE_EMAIL_KEY='.$key);
$queueCode();$root->exec("UPDATE commit_safe_emails SET ciphertext='invalid' WHERE status='queued'");$outbox->run($mailer);$checkTerminal('needs_review');
$queueCode();putenv('MAIL_ENABLED=false');SessionTest::deny(fn()=>$outbox->run($mailer),503,'Mail disabled prevents all dispatch');SessionTest::check($last()['status']==='queued','Disabled worker leaves request queued');putenv('MAIL_ENABLED=true');$outbox->run($mailer);$checkTerminal('accepted');

foreach (['400','429','500','unknown'] as $failure) {
    $queueCode();$mode=$failure;$before=count($sent);$outbox->run($mailer);$checkTerminal($failure==='400'?'failed':'needs_review');
    $outbox->run($mailer);SessionTest::check(count($sent)===$before+1,'No automatic resend after '.$failure);
}
$mode='accepted';
$queueCode();$root->exec("UPDATE commit_safe_emails SET status='sending',lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE status='queued'");$before=count($sent);$outbox->run($mailer);$checkTerminal('needs_review');SessionTest::check(count($sent)===$before,'Abandoned lease never resends');

// Required audit failures: enqueue rolls back, claim cannot send; final result failure leaves durable claim.
$root->exec("CREATE TRIGGER email_test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='account_email.delivery' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit unavailable'; END IF; END");
$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=1');$count=(int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn();
try {$prefs->sendVerification($actor,'audit-fail');throw new RuntimeException('Missing enqueue audit accepted');} catch (PDOException) {SessionTest::check((int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn()===$count,'Enqueue audit failure rolls back job');}
$root->exec('DROP TRIGGER email_test_audit_failure');$queueCode();
$root->exec("CREATE TRIGGER email_test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit unavailable'");
$before=count($sent);try {$outbox->run($mailer);throw new RuntimeException('Missing intent audit accepted');} catch (PDOException) {SessionTest::check(count($sent)===$before && $last()['status']==='queued','Intent audit failure prevents provider call');}$root->exec('DROP TRIGGER email_test_audit_failure');
$root->exec("CREATE TRIGGER email_test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='account_email.delivery' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.metadata,'$.state'))='accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic final audit unavailable'; END IF; END");
try {$outbox->run($mailer);throw new RuntimeException('Missing final audit accepted');} catch (PDOException) {SessionTest::check($last()['status']==='sending','Final audit failure retains durable uncertainty claim');}$root->exec('DROP TRIGGER email_test_audit_failure');
$root->exec("UPDATE commit_safe_emails SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE status='sending'");$outbox->run($mailer);$checkTerminal('needs_review');

$onboarding=new CustomerOnboarding($db,$config,1);
$invite=$onboarding->invite($actor,7,'synthetic-invitation','email');SessionTest::check($invite['delivery']==='email_queued' && !isset($invite['token']),'Email invite queued without returning reusable token');$outbox->run($mailer);$checkTerminal('accepted');
$message=end($sent);preg_match('/#token=([0-9a-f]{64})/',$message['body']['content'],$token);
SessionTest::check(isset($token[1]) && hash('sha256',$token[1])===$root->query('SELECT token_hash FROM client_link_invitations ORDER BY id DESC LIMIT 1')->fetchColumn(),'Invitation secret matches committed hash');
SessionTest::check(str_contains($message['body']['content'],'48 heures') && !str_contains($message['body']['content'],'Synthetic Fresh'),'Bilingual invitation contains no client name');
$onboarding->invite($actor,7,'synthetic-revoked','email');$root->exec('UPDATE client_link_invitations SET revoked_at=UTC_TIMESTAMP() WHERE client_id=7');$outbox->run($mailer);$checkTerminal('canceled');
$onboarding->invite($actor,7,'synthetic-consumed','email');$root->exec('UPDATE client_link_invitations SET consumed_at=UTC_TIMESTAMP() WHERE client_id=7');$outbox->run($mailer);$checkTerminal('canceled');
$onboarding->invite($actor,7,'synthetic-address','email');$root->exec("UPDATE users SET email='different@example.test' WHERE id=7");$outbox->run($mailer);$checkTerminal('canceled');$root->exec("UPDATE users SET email='fresh@example.test' WHERE id=7");
$manual=$onboarding->invite($actor,7,'synthetic-manual');SessionTest::check($manual['delivery']==='manual' && strlen($manual['token'])===64,'Manual invitation remains available');

$enquiries=new PractitionerEnquiryService($database);
$resetEnquiry=fn()=>$root->exec("UPDATE practitioner_enquiries SET notification_status='failed' WHERE id=1");
$resetEnquiry();$enquiries->retryNotification($admin,1,1,'synthetic-enquiry');$count=(int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn();$enquiries->retryNotification($admin,1,1,'duplicate');SessionTest::check((int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn()===$count,'Duplicate enquiry request cannot enqueue twice');
$outbox->run($mailer);$checkTerminal('accepted');SessionTest::check($root->query('SELECT notification_status FROM practitioner_enquiries WHERE id=1')->fetchColumn()==='accepted','Enquiry distinguishes provider acceptance');
SessionTest::check(!str_contains(end($sent)['body']['content'],'Never disclose') && !str_contains(end($sent)['body']['content'],'applicant@example.test'),'Enquiry email excludes recruitment body and applicant contact');
$resetEnquiry();$enquiries->retryNotification($actor,1,1,'settings-change');$root->exec('UPDATE practitioner_enquiry_settings SET enabled=0,revision=revision+1 WHERE clinic_id=1');$outbox->run($mailer);$checkTerminal('canceled');$root->exec('UPDATE practitioner_enquiry_settings SET enabled=1,revision=revision+1 WHERE clinic_id=1');
$resetEnquiry();$enquiries->retryNotification($admin,1,1,'central-revoke');$root->exec("UPDATE application_administrators SET status='revoked' WHERE id=1; UPDATE application_administrators SET status='active' WHERE id=1");$outbox->run($mailer);$checkTerminal('canceled');
SessionTest::deny(fn()=>$enquiries->retryNotification($actor,2,1,'wrong-clinic'),403,'Cross-clinic enquiry denied');

// Independent worker during provider call must not duplicate the claimed job. Authority writes wait.
$queueCode();$during=function() use($config,$mailer,$root): void {
    $other=new CommitSafeEmail((new Database($config))->connection());SessionTest::check($other->run($mailer)['accepted']===0,'Concurrent worker cannot claim a sending item');
    $root->exec('SET SESSION innodb_lock_wait_timeout=1');
    try {$root->exec("UPDATE users SET status='inactive' WHERE id=1");throw new RuntimeException('Authority change escaped dispatch locks');}
    catch (PDOException $e) {SessionTest::check((int)($e->errorInfo[1]??0)===1205,'Concurrent revocation serializes with single provider effect');}
};$outbox->run($mailer);$during=null;$checkTerminal('accepted');

// Recipient restrictions, link state and independent practitioner membership are current authority.
foreach (['patient_communications','staff_access'] as $scope) {
    $onboarding->invite($actor,7,'consent-'.$scope,'email');
    $root->prepare('INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,7,?,0,1,1)')->execute([$scope]);
    $before=count($sent);$outbox->run($mailer);$checkTerminal('canceled');SessionTest::check(count($sent)===$before,'Current '.$scope.' restriction prevents invitation');
    $root->exec('DELETE FROM client_consent_directives WHERE client_id=7');
}
$onboarding->invite($actor,7,'synthetic-linked','email');
$root->exec('UPDATE customer_client_links SET client_id=7 WHERE identity_id=1');$outbox->run($mailer);$checkTerminal('canceled');$root->exec('UPDATE customer_client_links SET client_id=3 WHERE identity_id=1');
$root->exec("INSERT INTO staff_notification_preferences(user_id,personal_email) VALUES(2,'personal@example.test')");
$external=new AuthContext(2,1,'api-microsoft','microsoft@example.test','Synthetic Microsoft','staff',['practitioner']);
$prefs->sendVerification($external,'external-code');$outbox->run($mailer);$checkTerminal('accepted');
$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=2');$prefs->sendVerification($external,'external-revoked');
$root->exec("UPDATE staff_memberships SET status='revoked' WHERE local_user_id=2; UPDATE staff_memberships SET status='active' WHERE local_user_id=2");$outbox->run($mailer);$checkTerminal('canceled');
$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=2');$prefs->sendVerification($external,'external-pilot-revoked');
$root->exec("UPDATE practitioner_pilot_access SET status='revoked' WHERE identity_id=2; UPDATE practitioner_pilot_access SET status='approved' WHERE identity_id=2");$outbox->run($mailer);$checkTerminal('canceled');
$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=1');$oldHash=$root->query('SELECT email_code_hash FROM staff_notification_preferences WHERE user_id=1')->fetchColumn();
putenv('COMMIT_SAFE_EMAIL_KEY=invalid');SessionTest::deny(fn()=>$prefs->sendVerification($actor,'missing-key'),503,'Malformed encryption key fails closed');putenv('COMMIT_SAFE_EMAIL_KEY='.$key);
SessionTest::check($root->query('SELECT email_code_hash FROM staff_notification_preferences WHERE user_id=1')->fetchColumn()===$oldHash,'Configuration failure preserves previous code');
putenv('COMMIT_SAFE_EMAIL_ENABLED=invalid');SessionTest::deny(fn()=>$prefs->sendVerification($actor,'invalid-flag'),503,'Malformed queue switch cannot fall back to synchronous email');putenv('COMMIT_SAFE_EMAIL_ENABLED=true');
foreach ([0,101] as $limit) {try {$outbox->run($mailer,$limit);throw new RuntimeException('Unbounded batch accepted');} catch (InvalidArgumentException) {SessionTest::check(true,'Batch limit bounded');}}
// At most one queued verification per user/minute even across competing connections.
$queueCode();$secondPrefs=new StaffNotificationPreferences(new Database($config),new AuditLogger(new Database($config)));
SessionTest::deny(fn()=>$secondPrefs->sendVerification($actor,'duplicate-code'),429,'Independent duplicate request throttled');$outbox->run($mailer);$checkTerminal('accepted');

// Practitioner invitation authority is separate from pilot admission or a matching email.
SessionTest::deny(fn()=>$onboarding->invite($external,7,'no-invite-permission','email'),403,'Practitioner without invitation permission denied');
$root->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 2,id FROM permissions WHERE code='add_clients'; INSERT INTO audit_logs(clinic_id,actor_user_id,correlation_id,action,entity_type,entity_id) VALUES(1,2,'synthetic-create','client.create','client',7)");
SessionTest::deny(fn()=>$onboarding->invite($external,7,'old-effective-permission','email'),403,'Later persisted permission cannot broaden original effective principal');
$creator=new AuthContext(2,1,'api-microsoft','microsoft@example.test','Synthetic Microsoft','staff',['practitioner'],['add_clients']);
SessionTest::deny(fn()=>$onboarding->invite($creator,7,'creator-without-care','email'),403,'Client creation history alone cannot authorize a practitioner invitation');
$root->exec('INSERT INTO client_care_assignments(clinic_id,client_id,practitioner_id,active,assigned_by,assigned_at) VALUES(1,7,1,1,1,UTC_TIMESTAMP())');
$onboarding->invite($creator,7,'creator-invitation','email');$outbox->run($mailer);$checkTerminal('accepted');
$onboarding->invite($creator,7,'care-revoked-before-send','email');
$root->exec('UPDATE client_care_assignments SET active=0,ended_by=1,ended_at=UTC_TIMESTAMP() WHERE clinic_id=1 AND client_id=7 AND practitioner_id=1');
$before=count($sent);$outbox->run($mailer);$checkTerminal('canceled');SessionTest::check(count($sent)===$before,'Revoked care assignment prevents queued invitation delivery');
$root->exec('UPDATE client_care_assignments SET active=1,ended_by=NULL,ended_at=NULL WHERE clinic_id=1 AND client_id=7 AND practitioner_id=1');
$onboarding->invite($creator,7,'creator-permission-revoke','email');
$root->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=2 AND p.code='add_clients'; INSERT INTO user_permissions(user_id,permission_id) SELECT 2,id FROM permissions WHERE code='add_clients'");$outbox->run($mailer);$checkTerminal('canceled');
$root->exec("INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,7,'patient_communications',0,1,1)");
$count=(int)$root->query('SELECT COUNT(*) FROM client_link_invitations')->fetchColumn();
SessionTest::deny(fn()=>$onboarding->invite($actor,7,'enqueue-restriction','email'),409,'Restricted invitation rejected at enqueue');
SessionTest::check((int)$root->query('SELECT COUNT(*) FROM client_link_invitations')->fetchColumn()===$count,'Denied enqueue rolls back invitation/history changes');$root->exec('DELETE FROM client_consent_directives WHERE client_id=7');
$unknown=new AuthContext(1,1,'unknown-principal','admin@example.test','Synthetic Admin','staff',['super_admin']);$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=1');
SessionTest::deny(fn()=>$prefs->sendVerification($unknown,'unknown-principal'),409,'Unknown exact identity cannot queue verification');
$queueCode();$root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=2');$prefs->sendVerification($external,'bounded-second');
$before=count($sent);$result=$outbox->run($mailer,1);SessionTest::check($result['accepted']===1 && count($sent)===$before+1,'Batch limit bounds actual provider effects');
SessionTest::check((int)$root->query("SELECT COUNT(*) FROM commit_safe_emails WHERE status='queued'")->fetchColumn()===1,'Remaining committed item preserved for next batch');$outbox->run($mailer,1);

require dirname(__DIR__).'/helpers/server-session-http.php';$http=new ServerSessionHttp($port,$name,true);
try {
    // The real API router has a separate private synthetic encryption key; no Graph credentials.
    $s=$http->start('workforce');$headers=['X-Session-CSRF'=>$s['csrf']];
    $root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=1');
    $reply=$http->call('profile/notifications/send-code','POST',$s['api'],$s['cookie'],[],$headers);
    SessionTest::check($reply['status']===200 && $reply['body']['data']['queued']===true,'Real session-protected verification route queues');
    $root->exec("CREATE TRIGGER email_access_audit_failure BEFORE INSERT ON access_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic final audit failure'");
    foreach (['profile/notifications/send-code','clients/7/invitations','admin/practitioner-enquiries/1/notify'] as $path) {
        $root->exec('UPDATE staff_notification_preferences SET email_code_sent_at=NULL WHERE user_id=1');$resetEnquiry();
        $count=(int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn();
        $reply=$http->call($path,'POST',$s['api'],$s['cookie'],$path==='clients/7/invitations'?['delivery'=>'email']:[],$headers);
        SessionTest::check($reply['status']===503,'Final request audit failure denied '.$path.': '.$reply['status']);
        SessionTest::check((int)$root->query('SELECT COUNT(*) FROM commit_safe_emails')->fetchColumn()===$count,'Final audit rolls back outbox for '.$path);
    }
    $root->exec('DROP TRIGGER email_access_audit_failure');
    foreach (['clients/7/invitations','admin/practitioner-enquiries/1/notify'] as $path) {
        $resetEnquiry();$reply=$http->call($path,'POST',$s['api'],$s['cookie'],$path==='clients/7/invitations'?['delivery'=>'email']:[],$headers);
        SessionTest::check($reply['status']===200 || $reply['status']===201,'Real session route commits queue for '.$path.': '.$reply['status']);
    }
    SessionTest::check($http->call('profile/notifications/send-code','POST',$s['api'],$s['cookie'],[],[])['status']===403,'CSRF remains mandatory');
} finally {$http->close();}
echo 'Commit-safe email integration: '.SessionTest::$checks." checks passed; synthetic disposable database only.\n";
