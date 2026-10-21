<?php
declare(strict_types=1);
// Reuse the established disposable booking fixture and its real authorization regression.
require __DIR__.'/booking-client-access.php';
use Firebase\JWT\JWT;
use Wellness\Service\CustomerOnboarding;

$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/049_access_audit_events.sql'));
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(1,'microsoft','audit-tenant','admin'),(2,'microsoft','audit-tenant','practitioner');
    INSERT INTO form_submissions(form_template_id,client_id,response_data,status,submitted_at) VALUES(1,101,'{\"symptom\":\"secret-clinical\",\"id\":556677}','submitted',UTC_TIMESTAMP());");
$submission=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO client_form_tasks(clinic_id,client_id,practitioner_id,form_template_id,assigned_by,status,submission_id) VALUES(1,101,2,1,1,'submitted',?)")->execute([$submission]);
$task=(int)$pdo->lastInsertId();
$claims=['iss'=>'https://11111111-1111-1111-1111-111111111111.ciamlogin.com/11111111-1111-1111-1111-111111111111/v2.0',
    'tid'=>'11111111-1111-1111-1111-111111111111','aud'=>'22222222-2222-2222-2222-222222222222','azp'=>'33333333-3333-3333-3333-333333333333',
    'sub'=>'synthetic-client','ver'=>'2.0','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+1800,'scp'=>'access_as_client'];
$pdo->prepare('INSERT INTO customer_identities(id,identity_hash,issuer,subject,created_at) VALUES(1,?,?,?,UTC_TIMESTAMP())')->execute([CustomerOnboarding::identityHash($claims),$claims['iss'],$claims['sub']]);
$pdo->exec('INSERT INTO customer_client_links(identity_id,client_id,clinic_id,created_at) VALUES(1,101,1,UTC_TIMESTAMP())');
$session=str_repeat('a',64);
$pdo->prepare('INSERT INTO customer_sessions(token_hash,identity_id,authenticated_at,created_at,last_activity_at,expires_at) VALUES(?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))')->execute([hash('sha256',$session)]);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
if(!$key)throw new RuntimeException('Synthetic RSA key unavailable');
$details=openssl_pkey_get_details($key);
$jwk=['kty'=>'RSA','kid'=>'synthetic-audit','alg'=>'RS256','n'=>JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>JWT::urlsafeB64Encode($details['rsa']['e'])];
$staffToken=function(string $subject,string $role)use($key):string{
    return JWT::encode(['iss'=>'https://login.microsoftonline.com/audit-tenant/v2.0','tid'=>'audit-tenant','aud'=>'audit-api','oid'=>$subject,
        'sub'=>$subject,'roles'=>[$role],'scp'=>'access_as_user','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+1800],$key,'RS256','synthetic-audit');
};
$adminToken=$staffToken('admin','Wellness.SuperAdmin');$practitionerToken=$staffToken('practitioner','Wellness.Practitioner');
$customerToken=JWT::encode($claims,$key,'RS256','synthetic-audit');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);
$env=getenv();$env['ACCESS_AUDIT_TEST_ALLOW']='synthetic-local-only';$env['ACCESS_AUDIT_TEST_DB']=$name;
$env['ACCESS_AUDIT_TEST_JWKS']=json_encode(['keys'=>[$jwk]],JSON_THROW_ON_ERROR);
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/access-audit-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/access-audit-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start synthetic audit API');fclose($pipes[0]);
$checks=0;$sequence=0;
$expect=function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$call=function(string $path,?string $token,string $method='GET',?array $body=null,string $host='audit-a.test',bool $customer=false)use($address,$session,&$sequence):array{
    $correlation='audit-http-'.++$sequence;$headers=['Host: '.$host,'Content-Type: application/json','X-Correlation-ID: '.$correlation];
    if($token!==null)$headers[]='Authorization: Bearer '.$token;
    if($customer)$headers[]='X-Customer-Session: '.$session;
    $curl=curl_init('http://'.$address.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    $raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException('Local HTTP failure: '.curl_error($curl));
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return [$status,json_decode($raw,true,64,JSON_THROW_ON_ERROR),$correlation,$raw];
};
$event=function(string $correlation)use($pdo):array{
    $q=$pdo->prepare('SELECT * FROM access_audit_events WHERE correlation_id=? ORDER BY part_number');$q->execute([$correlation]);
    $rows=$q->fetchAll();if(!$rows)throw new RuntimeException('Missing HTTP audit event: '.$correlation);
    return $rows;
};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}
    $expect($ready,'Synthetic API ready');
    [$status,$data,$cid]=$call('/api/v1/clients?q=Synthetic&email=private-search',$adminToken);
    $expect($status===200,'Operator search HTTP success');$rows=$event($cid);$row=$rows[0];
    $refs=json_decode($row['metadata'],true)['resources'];$returned=array_map('intval',array_column($data['data']['items'],'id'));sort($returned);
    $logged=array_column(array_filter($refs,fn($r)=>$r['type']==='client'),'id');sort($logged);
    $expect($logged===$returned&&$row['actor_type']==='staff'&&(int)$row['actor_id']===1,'Real returned IDs and verified actor logged before response');
    $expect(!str_contains(json_encode($rows),'private-search')&&!str_contains(json_encode($rows),'example.test'),'No contact/search content in access events');
    [$status,,$cid]=$call('/api/v1/clients',$practitionerToken);
    $row=$event($cid)[0];$expect($status===403&&$row['outcome']==='denied'&&(int)$row['actor_id']===2,'Role denial logged for verified practitioner');
    foreach([108,105,99999] as $target){
        [$status,,$cid]=$call('/api/v1/booking-clients/'.$target.'/address',$practitionerToken);
        $row=$event($cid)[0];$meta=json_decode($row['metadata'],true);
        $expect($status===404&&$row['outcome']==='denied'&&$meta['attempted_resources']===[['type'=>'client','id'=>$target]]&&$meta['resources']===[],'Concealed/foreign/missing address denial logged without confirming existence');
    }
    [$status,,$cid]=$call('/api/v1/clients',null);
    $row=$event($cid)[0];$expect($status===401&&$row['actor_type']==='anonymous'&&$row['actor_id']===null,'Missing credentials never supply actor IDs');
    [$status,,$cid]=$call('/api/v1/clients','forged.private-token');
    $row=$event($cid)[0];$expect($status===401&&$row['actor_type']==='anonymous','Forged credentials remain anonymous');
    [$status,$data,$cid]=$call('/api/v1/booking-clients?q=NoSuchSyntheticClient',$practitionerToken);
    $expect($status===200&&$data['data']['items']===[]&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Empty filtered search still audited');
    [$status,$data,$cid]=$call('/api/v1/practitioner/clients?q=Synthetic',$practitionerToken);
    $logged=array_column(json_decode($event($cid)[0]['metadata'],true)['resources'],'id');sort($logged);
    $returned=array_map('intval',array_column($data['data']['items'],'id'));sort($returned);
    $expect($status===200&&$logged===$returned,'Practitioner client directory search has complete returned-ID coverage');
    [$status,$data,$cid]=$call('/api/v1/appointments',$practitionerToken);
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$logged=array_column(array_filter($refs,fn($r)=>$r['type']==='appointment'),'id');sort($logged);
    $returned=array_map('intval',array_column($data['data'],'id'));sort($returned);
    $expect($status===200&&$logged===$returned,'Appointment list records each disclosed appointment');
    [$status,$data,$cid]=$call('/api/v1/practitioner/calendar?start=2026-01-01T00:00:00Z&end=2026-01-03T00:00:00Z',$practitionerToken);
    $expect($status===200&&count(json_decode($event($cid)[0]['metadata'],true)['resources'])===count($data['data']),'Practitioner calendar audited');
    [$status,$data,$cid]=$call('/api/v1/forms/tasks/'.$task,$practitionerToken);
    $row=$event($cid)[0];$expect($status===200&&$data['data']['answers']['symptom']==='secret-clinical','Assigned practitioner still reads form');
    $expect(!str_contains($row['metadata'],'secret-clinical')&&!str_contains($row['metadata'],'556677'),'Answer contents and embedded fake IDs never become audit metadata');
    [$status,,$cid]=$call('/api/v1/forms/tasks/'.$task,$adminToken);
    $expect($status===403&&$event($cid)[0]['outcome']==='denied','Administrator clinical-answer denial is audited');
    [$status,$data,$cid]=$call('/api/v1/customer/profile',$customerToken,'GET',null,'audit-a.test',true);
    $row=$event($cid)[0];$expect($status===200&&$row['actor_type']==='client'&&(int)$row['actor_id']===101&&json_decode($row['metadata'],true)['resources']===[['type'=>'client','id'=>101]],'Customer profile attribution and record ID');
    [$status,,$cid]=$call('/api/v1/customer/auth/me',$customerToken,'GET',null,'audit-a.test',true);
    $expect($status===200&&$event($cid)[0]['actor_type']==='customer_identity','Session status has verified customer identity attribution');
    $appointment=(int)$pdo->query('SELECT id FROM appointments WHERE client_id=101 LIMIT 1')->fetchColumn();
    [$status,$data,$cid]=$call('/api/v1/customer/appointments/'.$appointment.'/calendar',$customerToken,'GET',null,'audit-a.test',true);
    $expect($status===200&&str_contains($data['data']['content'],'BEGIN:VCALENDAR')&&$event($cid)[0]['action']==='access.download','Calendar export is audited without file content');
    [$status,,$cid]=$call('/api/v1/customer/forms/'.$task,$customerToken,'GET',null,'audit-a.test',true);
    $expect($status===200&&$event($cid)[0]['actor_type']==='client','Client clinical reads share the boundary');
    $pdo->exec("UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE identity_id=1");
    [$status,,$cid]=$call('/api/v1/customer/profile',$customerToken,'GET',null,'audit-a.test',true);
    $expect($status===401&&$event($cid)[0]['outcome']==='denied','Revoked customer session denial');
    $body=['client_id'=>108,'delivery_mode'=>'clinic','location_id'=>1,'practitioner_id'=>2,'service_id'=>1,'duration_option_id'=>1,'starts_at'=>'2027-01-20T10:00:00-05:00','idempotency_key'=>'audit-denied-write'];
    [$status,,$cid]=$call('/api/v1/appointments',$practitionerToken,'POST',$body);
    $expect($status===422&&$event($cid)[0]['outcome']==='denied','Writer authorization denial persists after rollback');
    $expect(in_array(['type'=>'client','id'=>108],json_decode($event($cid)[0]['metadata'],true)['attempted_resources'],true),'Denied submitted client ID is recorded only as an attempted target');
    [$status,,$cid]=$call('/api/v1/clients',$adminToken,'GET',null,'unknown-audit.test');
    $row=$event($cid)[0];$expect($status===404&&$row['clinic_id']===null&&$row['actor_type']==='anonymous','Unknown host denial does not trust Host or token claims for attribution');
    // Simulate unavailable audit storage after successful disclosure query, then during a denial.
    $pdo->exec('RENAME TABLE access_audit_events TO retained_access_audit_events');
    try{
        [$status,$data,,$raw]=$call('/api/v1/clients?q=Synthetic',$adminToken);
        $expect($status===503&&($data['error']['code']??null)==='access_audit_unavailable'&&!str_contains($raw,'client101@example.test'),'No sensitive JSON escapes when the access audit sink is unavailable');
        [$status,,$cid]=$call('/api/v1/clients',$practitionerToken);
        $expect($status===403,'Sink failure preserves denial status');
    }finally{$pdo->exec('RENAME TABLE retained_access_audit_events TO access_audit_events');}
    $all=$pdo->query('SELECT metadata FROM access_audit_events')->fetchAll(PDO::FETCH_COLUMN);
    $expect(!str_contains(implode('',$all),'secret-clinical')&&!str_contains(implode('',$all),'private-token')&&!str_contains(implode('',$all),$session),'No clinical text or credentials in persisted events');
}finally{
    proc_terminate($server);proc_close($server);
}
echo "API access audit SQL/HTTP acceptance: $checks checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
