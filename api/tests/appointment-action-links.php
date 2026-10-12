<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AppointmentActionLinks as Links;
use Wellness\Service\AppointmentEmail;
use Wellness\Service\AuditLogger;
use Wellness\Service\CancellationPolicy;
set_error_handler(static function(int $severity,string $message): never { throw new RuntimeException($message); });
$checks=0;
function check(bool $condition): void { global $checks; if(!$condition)throw new RuntimeException('Appointment link assertion failed');$checks++; }
function denies(callable $action,int $status): void {try{$action();}catch(ApiException $e){check($e->status===$status);return;}throw new RuntimeException('Expected denial');}
final class LinkPDO extends PDO {
 public array $queries=[];public bool $missing=false,$transaction=false,$issueAllowed=true,$failAudit=false;
 public function __construct(){}
 public function prepare(string $sql,array $options=[]): PDOStatement|false {return new LinkStatement($this,$sql);}
 public function beginTransaction(): bool {$this->transaction=true;return true;}
 public function commit(): bool {$this->transaction=false;return true;}
 public function rollBack(): bool {$this->transaction=false;return true;}
 public function inTransaction(): bool {return $this->transaction;}
}
final class LinkStatement extends PDOStatement {
 private mixed $result=false;private int $count=0;
 public function __construct(private LinkPDO $db,private string $sql){}
 public function execute(?array $params=null): bool {
  $p=$params??[];$this->db->queries[]=[$this->sql,$p];
  if(str_starts_with($this->sql,'INSERT INTO appointment_action_links'))$this->count=$this->db->issueAllowed?1:0;
  elseif(str_starts_with($this->sql,'SELECT a.id'))$this->result=!$this->db->missing&&$p['clinic']===1&&$p['client']===8&&$p['hash']===hash('sha256',str_repeat('a',64))?['id'=>71,'status'=>'confirmed','version'=>1]:false;
  elseif(str_starts_with($this->sql,'SELECT id FROM appointments'))$this->result=!$this->db->missing&&$p['clinic']===1&&$p['appointment']===71?71:false;
  elseif(str_starts_with($this->sql,'INSERT INTO audit_logs')){if($this->db->failAudit)throw new RuntimeException('Synthetic audit failure');}
  elseif(!str_starts_with($this->sql,'UPDATE appointment_action_links'))throw new RuntimeException('Unexpected query');
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->result;}
 public function fetchColumn(int $column=0): mixed {return $this->result;}
 public function rowCount(): int {return $this->count;}
}
$pdo=new LinkPDO();$config=new Config('test',false,'test',[],'',3306,'','','','workforce','api','scope',300);
$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$service=new Links($pdo,true,new AuditLogger($database));$disabled=new Links($pdo,false);
$client=new AuthContext(8,1,'','','','client',[]);$admin=new AuthContext(1,1,'','','','staff',['super_admin']);$token=str_repeat('a',64);
denies(fn()=>$disabled->resolve($client,['token'=>$token],'test'),503);denies(fn()=>$disabled->revoke($admin,71,'test'),503);
denies(fn()=>$service->resolve($admin,['token'=>$token],'test'),403);
foreach(['',[],null,str_repeat('a',63),str_repeat('g',64),str_repeat('A',64)] as $invalid)denies(fn()=>$service->resolve($client,['token'=>$invalid],'test'),404);
foreach([['client',[]],['staff',['practitioner']],['staff',['accountant']]] as [$type,$roles])denies(fn()=>$service->revoke(new AuthContext(8,1,'','','',$type,$roles),71,'test'),403);
check($pdo->queries===[]);
check($service->resolve($client,['token'=>$token],'test')['appointment']['id']===71);
[$sql,$params]=$pdo->queries[0];check($params===['hash'=>hash('sha256',$token),'clinic'=>1,'client'=>8]);
foreach(['a.version=link.appointment_version','link.revoked_at IS NULL','link.expires_at>UTC_TIMESTAMP()',"u.status='active'","c.status='active'",'a.starts_at>UTC_TIMESTAMP()',"a.status IN ('requested','confirmed','rescheduled')"] as $guard)check(str_contains($sql,$guard));
check(!str_contains(json_encode($pdo->queries),$token));
denies(fn()=>$service->resolve(new AuthContext(9,1,'','','','client',[]),['token'=>$token],'test'),404);
denies(fn()=>$service->resolve(new AuthContext(8,2,'','','','client',[]),['token'=>$token],'test'),404);
$pdo->missing=true;denies(fn()=>$service->resolve($client,['token'=>$token],'test'),404);denies(fn()=>$service->revoke($admin,71,'test'),404);check(!$pdo->inTransaction());$pdo->missing=false;
foreach(['super_admin','clinic_admin','reception'] as $role)check($service->revoke(new AuthContext(1,1,'','','','staff',[$role]),71,'test')['revoked']);
check(!$pdo->inTransaction());$pdo->failAudit=true;try{$service->revoke($admin,71,'test');throw new LogicException('Should fail');}catch(RuntimeException $e){check($e->getMessage()==='Synthetic audit failure');}check(!$pdo->inTransaction());$pdo->failAudit=false;
$event=['id'=>4,'version'=>1,'event_code'=>'booking_confirmation'];
check($disabled->issue($event,'https://clinic.example/client')===null);
foreach(['staff_booking_confirmation','booking_cancellation','unknown'] as $code)check($service->issue(array_replace($event,['event_code'=>$code]),'https://clinic.example/client')===null);
foreach(['booking_confirmation','booking_change','appointment_reminder'] as $code){$url=$service->issue(array_replace($event,['event_code'=>$code]),'https://clinic.example/client/');check((bool)preg_match('#^https://clinic.example/client/appointment\#token=([a-f0-9]{64})$#D',$url,$matches));$issued=$matches[1];$last=$pdo->queries[array_key_last($pdo->queries)];check($last[1]['hash']===hash('sha256',$issued)&&!str_contains(json_encode($last),$issued));check(str_contains($last[0],'n.recipient_user_id=u.id')&&str_contains($last[0],'n.recipient_address=u.email')&&str_contains($last[0],"n.status='sending'"));}
$pdo->issueAllowed=false;check($service->issue($event,'https://clinic.example/client')===null);
foreach(['http://clinic.example/client','https://user:pass@clinic.example/client','https://clinic.example/client?next=evil','https://clinic.example/client#fragment','https://clinic.example/','javascript:alert(1)'] as $url){try{Links::url($url,$token);throw new LogicException('Should fail');}catch(InvalidArgumentException){check(true);}}
$event+=['clinic_name'=>'Synthetic clinic','starts_at'=>'2099-10-01 12:00:00','timezone'=>'America/Toronto','appointment_action_url'=>Links::url('https://clinic.example/client',$token)];
$email=AppointmentEmail::compose($event,'https://clinic.example/client');check(substr_count($email['body'],$event['appointment_action_url'])===2&&str_contains($email['body'],'client sign-in required'));
try{AppointmentEmail::compose(array_replace($event,['appointment_action_url'=>'https://attacker.example/']), 'https://clinic.example/client');throw new LogicException('Should fail');}catch(InvalidArgumentException){check(true);}
CancellationPolicy::acknowledge(['expected_cancellation_fee_cents'=>0],['fee_cents'=>0]);check(true);
CancellationPolicy::acknowledge(['expected_cancellation_fee_cents'=>2500],['fee_cents'=>2500]);check(true);
foreach([[],['expected_cancellation_fee_cents'=>'2500'],['expected_cancellation_fee_cents'=>-1],['expected_cancellation_fee_cents'=>[]]] as $body)denies(fn()=>CancellationPolicy::acknowledge($body,['fee_cents'=>2500]),422);
denies(fn()=>CancellationPolicy::acknowledge(['expected_cancellation_fee_cents'=>0],['fee_cents'=>2500]),409);
echo "Appointment action links: $checks assertions passed (PDO double; real SQL acceptance separate).\n";
