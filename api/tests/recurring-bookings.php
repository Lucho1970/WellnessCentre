<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\{AddressCoverageService,AuditLogger,BookingService,RecurrencePattern,RecurringBookingService};
set_error_handler(static function(int $severity,string $message): never {throw new RuntimeException($message);});
$checks=0;
function check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Recurrence assertion failed');$checks++;}
function rejects(callable $action,string $code): void {try{$action();}catch(ApiException $e){check($e->errorCode===$code);return;}throw new RuntimeException('Expected '.$code);}
$dates=RecurrencePattern::dates('2026-10-06T10:00:00-04:00','America/Toronto',['frequency'=>'weekly','count'=>6]);
check(count($dates)===6);check($dates[4]['starts_at']==='2026-11-03T10:00:00-05:00');
$dates=RecurrencePattern::dates('2028-01-31T10:00:00-05:00','America/Toronto',['frequency'=>'monthly','count'=>4]);
check(array_column($dates,'local_time')===['2028-01-31 10:00:00','2028-02-29 10:00:00','2028-03-31 10:00:00','2028-04-30 10:00:00']);
$dates=RecurrencePattern::dates('2026-10-18T01:30:00-04:00','America/Toronto',['frequency'=>'biweekly','count'=>2]);check($dates[1]['starts_at']===null&&$dates[1]['error']!==null);
$dates=RecurrencePattern::dates('2026-03-01T02:30:00-05:00','America/Toronto',['frequency'=>'weekly','count'=>2]);check($dates[1]['starts_at']===null);
$dates=RecurrencePattern::dates('2026-03-01T10:00:00-05:00','America/Toronto',['frequency'=>'weekly','until'=>'2026-03-15']);check(count($dates)===3&&$dates[1]['starts_at']==='2026-03-08T10:00:00-04:00');
foreach([[],['frequency'=>'daily','count'=>2],['frequency'=>'weekly','count'=>1],['frequency'=>'weekly','count'=>27],['frequency'=>'weekly','count'=>2,'until'=>'2026-12-01'],['frequency'=>'monthly','until'=>'2026-02-30'],['frequency'=>'monthly','until'=>'2028-01-01']] as $pattern)rejects(fn()=>RecurrencePattern::dates('2026-10-06T10:00:00-04:00','America/Toronto',$pattern),'invalid_recurrence');
rejects(fn()=>RecurrencePattern::dates('2026-10-06T10:00:00Z','UTC',['frequency'=>'weekly','count'=>2,'interval_count'=>2]),'invalid_recurrence');
check(RecurrencePattern::dates('2026-10-06T10:00:00+02:00','+02:00',['frequency'=>'weekly','count'=>2])[1]['starts_at']==='2026-10-13T10:00:00+02:00');
check(RecurrencePattern::dates('2026-10-06T10:00:00Z','UTC',['frequency'=>'weekly','count'=>2])[1]['starts_at']==='2026-10-13T10:00:00+00:00');

/** A transactional double tests coordination, not SQL-engine locking/constraints. */
final class SeriesPDO extends PDO {
 public bool $transaction=false,$active=true;public int $writes=0,$commits=0,$rollbacks=0;public array $stored=[];private array $snapshot=[];private int $savepoint=0;
 public function __construct(){}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return new SeriesStatement($this,$query);}
 public function beginTransaction(): bool {$this->snapshot=[$this->writes,$this->stored];$this->transaction=true;return true;}
 public function inTransaction(): bool {return $this->transaction;}
 public function commit(): bool {$this->commits++;$this->transaction=false;return true;}
 public function rollBack(): bool {[$this->writes,$this->stored]=$this->snapshot;$this->rollbacks++;$this->transaction=false;return true;}
 public function exec(string $query): int|false {if($query==='SAVEPOINT recurring_occurrence')$this->savepoint=$this->writes;if($query==='ROLLBACK TO SAVEPOINT recurring_occurrence')$this->writes=$this->savepoint;return 0;}
}
final class SeriesStatement extends PDOStatement {
 private mixed $result=false;
 public function __construct(private SeriesPDO $db,private string $sql){}
 public function execute(?array $params=null): bool {
  $p=$params??[];
  if(str_starts_with($this->sql,'SELECT id FROM clinics'))$this->result=$this->db->active?1:false;
  elseif(str_starts_with($this->sql,'SELECT actor_user_id'))$this->result=$this->db->stored[$p['clinic'].':'.$p['key']]??false;
  elseif(str_starts_with($this->sql,'INSERT INTO recurring_booking_requests'))$this->db->stored[$p['clinic'].':'.$p['key']]=['actor_user_id'=>$p['actor'],'request_hash'=>$p['hash'],'result_json'=>$p['result']];
  elseif(str_starts_with($this->sql,'SELECT id,recurring_series_id'))$this->result=$p['clinic']===1?['id'=>$p['id'],'client_id'=>8,'practitioner_id'=>3]:false;
  else throw new RuntimeException('Unexpected SQL: '.$this->sql);
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->result;}
 public function fetchColumn(int $column=0): mixed {return $this->result;}
}
$_ENV['MAIL_ENABLED']='false';
$config=new Config('test',false,'test',[],'',3306,'','','','workforce','api','scope',300);$pdo=new SeriesPDO();$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$bookings=new BookingService($database,new AuditLogger($database),new AddressCoverageService($database,$config));$service=new RecurringBookingService($database,$bookings);
$actor=new AuthContext(8,1,'','','','client',[]);$other=new AuthContext(9,1,'','','','client',[]);
$transaction=new ReflectionMethod($service,'transaction');$occurrence=new ReflectionMethod($service,'occurrence');
$body=['idempotency_key'=>'synthetic-series-key','recurrence'=>['frequency'=>'weekly','count'=>2]];
$writer=function()use($pdo){$pdo->writes+=2;return ['series_id'=>10,'timezone'=>'UTC','items'=>[['appointment_id'=>41,'starts_at'=>'2027-01-01T10:00:00Z','subtotal_cents'=>5000,'ok'=>true],['appointment_id'=>42,'starts_at'=>'2027-01-08T10:00:00Z','subtotal_cents'=>5000,'ok'=>true]]];};
$preview=$transaction->invoke($service,$actor,$body,false,$writer);check($preview['ready']&&!$preview['applied']&&$preview['series_id']===null&&!isset($preview['items'][0]['appointment_id']));check($pdo->writes===0&&$pdo->commits===0&&!$pdo->inTransaction());
rejects(fn()=>$transaction->invoke($service,$actor,$body+['preview_token'=>'wrong'],true,$writer),'series_preview_changed');check($pdo->writes===0&&$pdo->stored===[]);
$changed=function()use($writer){$result=$writer();$result['items'][0]['subtotal_cents']=6000;return $result;};
rejects(fn()=>$transaction->invoke($service,$actor,$body+['preview_token'=>$preview['preview_token']],true,$changed),'series_preview_changed');check($pdo->writes===0);
$conflict=function()use($writer){$result=$writer();$result['items'][1]=['ok'=>false,'code'=>'schedule_conflict','message'=>'Occupied','starts_at'=>'2027-01-08T10:00:00Z'];return $result;};
$failed=$transaction->invoke($service,$actor,$body+['preview_token'=>$preview['preview_token']],true,$conflict);check(!$failed['ready']&&!$failed['applied']&&$pdo->writes===0&&$pdo->stored===[]);
$result=$transaction->invoke($service,$actor,$body+['preview_token'=>$preview['preview_token']],true,$writer);check($result['applied']&&$pdo->writes===2&&$pdo->commits===1);
$never=static function(){throw new RuntimeException('Replay must not invoke the writer');};
check($transaction->invoke($service,$actor,$body,true,$never)===$result&&$pdo->writes===2&&$pdo->commits===1);
rejects(fn()=>$transaction->invoke($service,$other,$body,true,$never),'idempotency_conflict');
rejects(fn()=>$transaction->invoke($service,$actor,array_replace($body,['recurrence'=>['frequency'=>'monthly','count'=>2]]),true,$never),'idempotency_conflict');
$pdo->active=false;rejects(fn()=>$transaction->invoke($service,$actor,$body,true,$never),'forbidden');$pdo->active=true;
$pdo->beginTransaction();$conflicting=static function()use($pdo){$pdo->writes++;throw new ApiException(409,'schedule_conflict','Occupied');};
check(!$occurrence->invoke($service,$conflicting)['ok']&&$pdo->writes===2);$pdo->rollBack();
rejects(fn()=>$service->create(new AuthContext(1,1,'','','','staff',['accounting']),$body,'test',false),'forbidden');
rejects(fn()=>$service->create($actor,$body+['client_id'=>9],'test',false),'validation_error');
rejects(fn()=>$service->change($actor,1,$body+['action'=>'reassign','items'=>[]],'test',false),'validation_error');
rejects(fn()=>$bookings->create($actor,$body,'test'),'invalid_recurrence');
rejects(fn()=>$bookings->update($actor,41,['scope'=>'series'],'test'),'validation_error');
check(!$pdo->inTransaction());
echo "Recurring booking policies/transaction coordination: {$checks} checks passed (PDO double; hosted SQL acceptance separate).\n";
