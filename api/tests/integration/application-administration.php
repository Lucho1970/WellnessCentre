<?php
declare(strict_types=1);
// Synthetic localhost database only. No .env or hosted credentials are loaded.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Auth\StaffMembershipResolver;
use Wellness\Auth\VerifiedIdentity;
use Wellness\Auth\EntraAuthenticator;
use Firebase\JWT\JWT;
use Wellness\ClinicContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Http\Request;
use Wellness\Service\AdminService;
use Wellness\Service\AuditLogger;
use Wellness\Service\CatalogService;
use Wellness\Service\ApplicationClinicService;
use Wellness\Auth\ApplicationAdminAuthenticator;
use Wellness\Service\ClinicPortalUrl;
use Wellness\Service\CustomerOnboarding;
use Wellness\Service\ClientService;

if (getenv('CLINIC_TEST_ALLOW_CREATE')!=='true') throw new RuntimeException('Set CLINIC_TEST_ALLOW_CREATE=true for the synthetic localhost rehearsal.');
$port=(int)(getenv('CLINIC_TEST_PORT')?:13319);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('CLINIC_TEST_USER')?:'root',getenv('CLINIC_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_clinic_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/005_customer_onboarding.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/039_separate_clinic_portals.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/040_application_administration.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Willow Virtual'); INSERT INTO locations(clinic_id,name) VALUES(1,'Willow Virtual');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(1,1,'admin@example.test','Operator','staff','active');
 INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin';
 INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(1,'microsoft','trusted-tenant','operator-subject');");
$config=new Config('test',false,'test-key',[],'127.0.0.1',$port,$name,'root','','trusted-tenant','api','scope',300,
 clinicHostMap:['livin.test'=>1],clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test');
$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$key)throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($key)['rsa'];$jwk=['kty'=>'RSA','kid'=>'test','n'=>JWT::urlsafeB64Encode($rsa['n']),'e'=>JWT::urlsafeB64Encode($rsa['e'])];
$entra=new EntraAuthenticator($config,$database,fn()=>['keys'=>[$jwk]]);
$auth=new ApplicationAdminAuthenticator($database,$entra);
$claims=['iss'=>'https://login.microsoftonline.com/trusted-tenant/v2.0','tid'=>'trusted-tenant','aud'=>'api','oid'=>'operator-subject','scp'=>'scope','roles'=>['Wellness.SuperAdmin'],'iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
$sign=fn(array $c)=>JWT::encode($c,$key,'RS256','test');$token=$sign($claims);
$checks=0;$check=function(bool $ok,string $why='Central isolation assertion failed')use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=function(callable $fn,int $status)use($check){try{$fn();}catch(ApiException $e){$check($e->status===$status,$e->errorCode);return;}throw new RuntimeException('Expected denial');};
$deny(fn()=>$auth->authenticate($token),403);
$pdo->prepare("INSERT INTO application_administrators(issuer,subject,display_name,email) VALUES(?,?,?,?)")->execute([$claims['iss'],$claims['oid'],'Operator','operator@example.test']);
$actor=$auth->authenticate($token);$check($actor->id===1);
$check($auth->authenticate($sign(array_replace($claims,['roles'=>[]])))->id===1,'Central grant must be independent of clinic directory roles');
$deny(fn()=>$auth->authenticate(null),401);
$deny(fn()=>$auth->authenticate($sign(array_replace($claims,['aud'=>'customer-api']))),401);
$deny(fn()=>$auth->authenticate($sign(array_replace($claims,['iss'=>'https://other.test']))),401);
$deny(fn()=>$auth->authenticate($sign(array_replace($claims,['scp'=>'other']))),403);
$deny(fn()=>$auth->authenticate($sign(array_replace($claims,['oid'=>'other-admin','email'=>'operator@example.test']))),403);
$manage=new ApplicationClinicService($database,new AuditLogger($database),$config);
$manage->configure($actor,1,['name'=>"Livin Lively",'portal_host'=>'livin.test','website_url'=>'https://livinlively.ca'],'test');
$deny(fn()=>$manage->configure($actor,999,['name'=>'Missing','portal_host'=>'missing.test'],'test'),404);
$new=$manage->create($actor,['name'=>'Willow Wellness Virtual Clinic','portal_host'=>'willow.test','initial_location_name'=>'Virtual'],'test');$id=(int)$new['id'];
$check($id!==1);$check(count($manage->list($actor)['items'])===2);
$check((int)$pdo->query("SELECT COUNT(*) FROM staff_memberships WHERE clinic_id=1")->fetchColumn()===0,'Global grant must not give access to existing clinics');
$check((int)$pdo->query("SELECT COUNT(*) FROM staff_memberships WHERE clinic_id=$id AND status='active'")->fetchColumn()===1);
$check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE clinic_id=$id")->fetchColumn()===1);
$check((int)$pdo->query("SELECT COUNT(*) FROM locations WHERE clinic_id=$id")->fetchColumn()===1);
$deny(fn()=>$manage->create($actor,['name'=>'Duplicate','portal_host'=>'willow.test'],'test'),409);
$check(count($manage->list($actor)['items'])===2,'Duplicate must roll back');
$deny(fn()=>$manage->create($actor,['name'=>'Reserved','portal_host'=>'portal.test'],'test'),409);
$deny(fn()=>$manage->configure($actor,$id,['name'=>'Conflict','portal_host'=>'livin.test'],'test'),409);
$manage->configure($actor,$id,['name'=>'Willow Virtual','portal_host'=>'new-willow.test'],'test');
$check($pdo->query("SELECT host FROM clinic_hosts WHERE clinic_id=$id")->fetchColumn()==='new-willow.test');
$check((int)$pdo->query("SELECT COUNT(*) FROM locations WHERE clinic_id=$id")->fetchColumn()===1,'Host edit must not move records');
$check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'application.%' AND actor_user_id IS NULL")->fetchColumn()===3);
// Exercise the real HTTP dispatch, including host gates and legacy clinic endpoints.
$fixtureFile=tempnam(sys_get_temp_dir(),'central-test-');
$routerFile=tempnam(sys_get_temp_dir(),'central-router-');
file_put_contents($fixtureFile,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk],JSON_THROW_ON_ERROR));
$autoload=var_export(dirname(__DIR__,2).'/vendor/autoload.php',true);
$fixture=var_export($fixtureFile,true);
file_put_contents($routerFile,"<?php\nrequire $autoload;\n\$f=json_decode(file_get_contents($fixture),true);\n" . <<<'PHP'
$config=new Wellness\Config('test',false,'test-key',[],'127.0.0.1',$f['port'],$f['database'],'root','','trusted-tenant','api','scope',300,clinicHostMap:['livin.test'=>1],clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test');
$database=new Wellness\Database($config);
$api=new Wellness\Api($config,$database);
(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$database,fn()=>['keys'=>[$f['jwk']]]));
$api->handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
if (!$socket) throw new RuntimeException($errstr);
$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-S',$address,$routerFile],[['pipe','r'],['file',$routerFile.'.log','a'],['file',$routerFile.'.log','a']],$pipes);
try {
    $request=function(string $host,string $path,?string $bearer=null,string $method='GET')use($address):array{
        $curl=curl_init('http://'.$address.$path);
        $headers=['Host: '.$host];if($bearer)$headers[]='Authorization: Bearer '.$bearer;
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        return [$status,is_string($body)?json_decode($body,true):null];
    };
    for($attempt=0;$attempt<30;$attempt++){if($request('portal.test','/api/v1/health')[0])break;usleep(100000);}
    $check($request('portal.test','/api/v1/application/me',$token)[0]===200);
    $check($request('portal.test','/api/v1/application/me')[0]===401);
    $check($request('livin.test','/api/v1/application/clinics',$token)[0]===404);
    $check($request('untrusted.test','/api/v1/application/clinics',$token)[0]===404);
    $check($request('portal.test','/api/v1/clients',$token)[0]===404);
    $check($request('portal.test','/api/v1/application/clients',$token)[0]===404);
    $check($request('livin.test','/api/v1/admin/clinics',$token)[0]===403);
    $check($request('livin.test','/api/v1/admin/clinics',$token,'POST')[0]===403);
    $check($request('livin.test','/api/v1/admin/clinics/1',$token,'PATCH')[0]===403);
    $pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
    $check($request('portal.test','/api/v1/application/me',$token)[0]===403);
} finally {
    if(is_resource($server)){proc_terminate($server);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($server);}
    unlink($fixtureFile);unlink($routerFile);
}
$deny(fn()=>$auth->authenticate($token),403);$deny(fn()=>$manage->list($actor),403);
echo "$checks real MariaDB central administration assertions passed. Synthetic database retained: $name\n";
