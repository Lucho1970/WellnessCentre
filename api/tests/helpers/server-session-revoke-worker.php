<?php
declare(strict_types=1);
require __DIR__.'/server-session-fixture.php';
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Disposable runtime required.');
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if(!preg_match('/^wellness_session_integration_[a-f0-9]{12}$/D',$input['database']) || $input['port']!==(int)getenv('RECURRING_TEST_PORT'))throw new RuntimeException('Invalid disposable database.');
$database=new Wellness\Database(ServerSessionFixture::config($input['port'],$input['database']));
$actor=new Wellness\Auth\AuthContext(1,1,ServerSessionFixture::oid('workforce'),'admin@example.test','Synthetic Admin','staff',['super_admin']);
echo "ready\n";flush();
(new Wellness\Service\AdminService($database,new Wellness\Service\AuditLogger($database)))->updateStaff($actor,2,['display_name'=>'Synthetic Microsoft','email'=>'microsoft@example.test','status'=>'inactive','roles'=>['practitioner'],'permissions'=>[]],'synthetic-concurrent-revoke');
echo "revoked\n";
