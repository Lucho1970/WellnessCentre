<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Config;
use Wellness\Service\CustomerOnboarding;
use Wellness\Http\ApiException;
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if(!preg_match('/^wellness_onboarding_test_[a-f0-9]{12}$/D',$input['database']??''))throw new RuntimeException('Scratch database required');
$port=(int)$input['port'];$name=$input['database'];
$db=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",getenv('ONBOARDING_TEST_USER')?:'root',getenv('ONBOARDING_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
$c=new CustomerOnboarding($db,new Config('test',false,'test-key',[],'127.0.0.1',$port,$name,'root','','staff','staff-api','scope',3600,customerOnboardingEnabled:true,customerClinicId:1));
try {
 if($input['action']==='accept')$result=$c->accept($input['identity'],$input['body'],'race-test');
 elseif($input['action']==='register')$result=$c->register($input['identity'],$input['body'],'race-test');
 elseif($input['action']==='approve'){$c->review(new \Wellness\Auth\AuthContext(1,1,'staff','staff@example.test','Staff','staff',['super_admin']),$input['client'],$input['invitation'],$input['body'],'race-test');$result=['onboarding_status'=>'linked'];}
 else throw new RuntimeException('Unsupported race operation');
 echo json_encode(['ok'=>true,'status'=>$result['onboarding_status']]);
}catch(ApiException $e){echo json_encode(['ok'=>false,'status'=>$e->status]);}
