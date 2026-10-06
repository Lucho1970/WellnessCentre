<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
set_error_handler(static function(int $severity,string $message): never {throw new RuntimeException($message);});
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AuditLogger;
use Wellness\Service\ClientOverviewService as Overview;

$checks=0;
function check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Client overview assertion failed');$checks++;}
function denies(callable $f,int $status): void {try{$f();}catch(ApiException $e){check($e->status===$status);return;}throw new RuntimeException('Expected overview denial');}
$row=['account_status'=>'active','practitioner_active'=>1,'roles'=>'practitioner','permissions'=>'','appointment_count'=>0,'created_client'=>0,'booking_mode'=>'practitioner_managed'];
$access=Overview::configuredAccess($row);
check(!$access['client_directory']&&$access['booking_contact']&&$access['appointments']==='own'&&$access['logistics_notes']==='own');
check($access['appointment_changes']==='own'&&!$access['client_administration']);
check(Overview::configuredAccess(array_replace($row,['appointment_count'=>1]))['client_directory']);
check(Overview::configuredAccess(array_replace($row,['created_client'=>1]))['client_directory']);
check(!Overview::configuredAccess($row,'inactive')['booking_contact']);
$access=Overview::configuredAccess(array_replace($row,['permissions'=>'schedule_for_other_practitioners']));
check($access['appointments']==='clinic'&&$access['appointment_changes']==='clinic'&&$access['logistics_notes']==='own'&&!$access['client_directory']);
check(Overview::configuredAccess(array_replace($row,['booking_mode'=>'clinic_managed']))['appointment_changes']==='none');
$inactiveProfile=Overview::configuredAccess(array_replace($row,['practitioner_active'=>0,'appointment_count'=>1]));
check($inactiveProfile['client_directory']&&$inactiveProfile['appointments']==='own'&&$inactiveProfile['appointment_changes']==='none');
foreach(['inactive','locked','pending'] as $status){$denied=Overview::configuredAccess(array_replace($row,['account_status'=>$status,'roles'=>'super_admin,practitioner','appointment_count'=>10]));check(!$denied['client_directory']&&!$denied['booking_contact']&&$denied['appointments']==='none'&&$denied['logistics_notes']==='none');}
foreach(['super_admin','clinic_admin','reception'] as $role){$operator=Overview::configuredAccess(array_replace($row,['roles'=>$role]));check($operator['client_administration']&&$operator['appointments']==='clinic'&&$operator['logistics_notes']==='clinic');}
$none=Overview::configuredAccess(array_replace($row,['roles'=>'accountant','permissions'=>'schedule_for_other_practitioners']));check(!$none['booking_contact']&&$none['appointments']==='none');
$external=array_replace($row,['identity_adapter'=>'entra-external-staff','identity_status'=>'active','membership_status'=>'active','roles'=>'super_admin,practitioner','appointment_count'=>1]);
$restricted=Overview::configuredAccess($external);check(!$restricted['client_administration']&&$restricted['appointments']==='own'&&$restricted['logistics_notes']==='own');
check(Overview::configuredAccess(array_replace($external,['membership_status'=>'revoked']))['appointments']==='none');
check(!Overview::configuredAccess(array_replace($row,['membership_required'=>true]))['staff_configuration_active']);
foreach([['page'=>0],['page'=>-1],['page'=>'1.5'],['page'=>100001],['page'=>[]]] as $invalid)denies(fn()=>Overview::page($invalid),422);
foreach(['other',[],null] as $invalid){if($invalid===null)continue;denies(fn()=>Overview::view(['view'=>$invalid]),422);}
check(Overview::page([])===1&&Overview::view([])==='all');

final class OverviewPDO extends PDO {
 public array $queries=[],$appointments=[],$practitioners=[],$changes=[];
 public bool $missing=false;
 public function __construct(){}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return new OverviewStatement($this,$query);}
}
final class OverviewStatement extends PDOStatement {
 private mixed $result=false;
 public function __construct(private OverviewPDO $db,private string $sql){}
 public function execute(?array $params=null): bool {
  $p=$params??[];$this->db->queries[]=[$this->sql,$p];
  if(str_contains($this->sql,'SELECT u.id,u.display_name,u.status'))$this->result=(!$this->db->missing&&$p===['client'=>7,'clinic'=>1])?['id'=>7,'display_name'=>'Selected Client','status'=>'active']:false;
  elseif(str_starts_with($this->sql,'SELECT a.id,l.timezone,a.currency'))$this->result=$p===['appointment'=>71,'client'=>7,'clinic'=>1]?['id'=>71,'timezone'=>'America/Toronto','currency'=>'CAD']:false;
  elseif(str_starts_with($this->sql,'SELECT e.kind'))$this->result=$this->db->changes;
  elseif(str_contains($this->sql,'SELECT COUNT(*) total'))$this->result=['total'=>'26','upcoming'=>'2','past'=>'24','canceled'=>'3'];
  elseif(str_starts_with($this->sql,'SELECT p.id practitioner_id'))$this->result=$this->db->practitioners;
  elseif(str_contains($this->sql,'FROM appointments a'))$this->result=$this->db->appointments;
  elseif(str_starts_with($this->sql,'INSERT INTO audit_logs'))$this->result=[];
  else throw new RuntimeException('Unexpected overview query');
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->result;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->result;}
}
$config=new Config('test',false,'test',[],'',3306,'','','','workforce','api','scope',300);
$pdo=new OverviewPDO();$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$service=new Overview($database,new AuditLogger($database),$config);
$actor=new AuthContext(1,1,'','admin@example.test','Admin','staff',['super_admin']);
foreach([['staff',['practitioner']],['staff',['accountant']],['client',['super_admin']],['staff',[]]] as [$type,$roles]){
 $denied=new AuthContext(2,1,'','','',$type,$roles);
 denies(fn()=>$service->appointments($denied,7,[],'test'),403);denies(fn()=>$service->practitionerAccess($denied,7,[],'test'),403);
}
check($pdo->queries===[]);
denies(fn()=>$service->appointments($actor,99,[],'test'),404);check(count($pdo->queries)===1);
$pdo->queries=[];
$pdo->appointments=array_map(fn($id)=>['id'=>$id,'status'=>$id===1?'canceled_by_client':'completed'],range(1,26));
$history=$service->appointments($actor,7,['page'=>2,'view'=>'past'],'test');
check(count($history['items'])===25&&$history['has_more']&&$history['page']===2&&$history['counts']['canceled']===3);
$sql=$pdo->queries[1][0];check(str_contains($sql,'a.clinic_id=:clinic AND a.client_id=:client')&&str_contains($sql,'a.ends_at<UTC_TIMESTAMP()')&&str_contains($sql,'LIMIT 26 OFFSET 25'));
check($pdo->queries[1][1]===['clinic'=>1,'client'=>7]);
check(str_contains($pdo->queries[0][0],"u.user_type='client'")&&str_contains($pdo->queries[0][0],'client_merge_records'));
check(!str_contains($sql,'destination_snapshot')&&!str_contains($sql,'administrative_notes'));
$audit=$pdo->queries[3][1];check($audit['action']==='client.appointments.view'&&$audit['entity']===7&&!str_contains($audit['metadata'],'Selected Client'));
foreach(['upcoming','canceled','all'] as $view){$pdo->queries=[];$service->appointments($actor,7,['view'=>$view],'test');$sql=$pdo->queries[1][0];check(match($view){'upcoming'=>str_contains($sql,"status IN ('requested','confirmed','rescheduled')")&&str_contains($sql,'ORDER BY a.starts_at ASC'),'canceled'=>str_contains($sql,"status IN ('canceled_by_client','canceled_by_clinic')"),default=>!str_contains($sql,'status IN')});}
$pdo->queries=[];$pdo->practitioners=[array_replace($row,['practitioner_id'=>3,'user_id'=>3,'display_name'=>'Practitioner','discipline'=>'Care','first_appointment_at'=>null,'last_appointment_at'=>null])];
$report=$service->practitionerAccess($actor,7,[],'test');
check($report['basis']==='local_configuration'&&count($report['items'])===1&&!$report['items'][0]['configured_access']['client_directory']);
$sql=$pdo->queries[1][0];check(str_contains($sql,'u.clinic_id=:clinic')&&str_contains($sql,'a.clinic_id=:history_clinic AND a.client_id=:history_client'));
check($pdo->queries[1][1]===['created_client'=>7,'clinic'=>1,'history_clinic'=>1,'history_client'=>7]);
check(str_contains($sql,"created.action='client.create'")&&str_contains($sql,"created.outcome='success'"));
$pdo->queries=[];denies(fn()=>$service->appointmentChanges($actor,7,99,[],'test'),404);check(count($pdo->queries)===2);
$pdo->queries=[];$pdo->changes=[['id'=>1,'kind'=>'status','from_status'=>'confirmed','to_status'=>'canceled_by_client','reason'=>'Requested cancellation']];
$changes=$service->appointmentChanges($actor,7,71,[],'test');check($changes['appointment']['id']===71&&count($changes['items'])===1&&!$changes['has_more']);
$sql=$pdo->queries[2][0];check(str_contains($sql,'a.id=:appointment AND a.client_id=:client AND a.clinic_id=:clinic')&&str_contains($sql,'appointment_reassignments')&&str_contains($sql,'cancellation_adjustments'));
check($pdo->queries[2][1]===['status_appointment'=>71,'reassignment_appointment'=>71,'reassignment_clinic'=>1,'fee_appointment'=>71,'appointment'=>71,'client'=>7,'clinic'=>1]);
check(!str_contains($sql,'practitioner_client_notes')&&str_contains($sql,'actor.clinic_id=a.clinic_id'));
$otherClinic=new AuthContext(3,2,'','admin@example.test','Admin','staff',['super_admin']);
$pdo->queries=[];denies(fn()=>$service->practitionerAccess($otherClinic,7,[],'test'),404);check(count($pdo->queries)===1);
echo "$checks client overview policy/query checks passed (PDO double).\n";
