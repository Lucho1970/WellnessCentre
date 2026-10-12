<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Auth\VerifiedIdentity;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AuditLogger;
use Wellness\Service\StaffInvitationService as Invitations;

// Policy/transaction double. Real MySQL acceptance has a separate integration script.
final class InvitationPDO extends PDO {
 public array|false $invite=false,$claim=false,$identity=false;
 public bool $manager=true,$location=true,$member=false,$duplicateEmail=false,$failAudit=false,$transaction=false;
 public array $writes=[],$saved=[];
 public int $last=10,$commits=0,$rollbacks=0;
 public function __construct(){}
 public function beginTransaction(): bool {$this->transaction=true;$this->saved=[$this->invite,$this->claim,$this->writes,$this->last];return true;}
 public function commit(): bool {$this->transaction=false;$this->commits++;return true;}
 public function rollBack(): bool {[$this->invite,$this->claim,$this->writes,$this->last]=$this->saved;$this->transaction=false;$this->rollbacks++;return true;}
 public function inTransaction(): bool {return $this->transaction;}
 public function lastInsertId(?string $name=null): string|false {return (string)$this->last;}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return new InvitationStatement($this,$query);}
}
final class InvitationStatement extends PDOStatement {
 private mixed $result=false;
 public function __construct(private InvitationPDO $db,private string $sql){}
 public function execute(?array $params=null): bool {
  $p=$params??[];$s=$this->sql;$this->result=false;
  if(str_starts_with($s,'SELECT')) {
   if(str_contains($s,'FROM staff_invitations')) {
    $clinic=str_contains($s,'token_hash')?$p[0]:$p[1];
    $this->result=$this->db->invite&&$this->db->invite['clinic_id']===$clinic?$this->db->invite:false;
   } elseif(str_contains($s,'FROM staff_invitation_claims')) $this->result=$this->db->claim;
   elseif(str_contains($s,'FROM product_identities'))$this->result=$this->db->identity;
   elseif(str_contains($s,'FROM staff_memberships'))$this->result=$this->db->member?5:false;
   elseif(str_contains($s,"r.code='super_admin'"))$this->result=$this->db->manager?1:false;
   elseif(str_contains($s,'FROM locations'))$this->result=$this->db->location?1:false;
   elseif(str_contains($s,'FROM users WHERE clinic_id=? AND email=?'))$this->result=$this->db->duplicateEmail?2:false;
   elseif(str_contains($s,"r.code='practitioner'"))$this->result=1;
   else throw new RuntimeException('Unexpected SELECT: '.$s);
  } else {
   if(str_starts_with($s,'INSERT INTO audit_logs')&&$this->db->failAudit)throw new PDOException('Audit unavailable');
   $this->db->writes[]=[$s,$p];
   if(str_starts_with($s,'INSERT'))$this->db->last++;
   if(str_starts_with($s,'INSERT INTO staff_invitation_claims'))$this->db->claim=['id'=>$this->db->last,'issuer'=>$p[1],'subject'=>$p[2],'claimant_name'=>$p[3],'verification_code'=>$p[4],'status'=>'pending'];
   if(str_starts_with($s,'UPDATE staff_invitations SET accepted_at'))$this->db->invite['accepted_at']=gmdate('Y-m-d H:i:s');
   if(str_starts_with($s,'UPDATE staff_invitations SET revoked_at'))$this->db->invite['revoked_at']=gmdate('Y-m-d H:i:s');
  }
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->result;}
 public function fetchColumn(int $column=0): mixed {return $this->result;}
}
$checks=0;
function check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('Invitation assertion failed');$checks++;}
function denies(callable $action,string $code): void {try{$action();}catch(ApiException $e){check($e->errorCode===$code);return;}throw new RuntimeException('Expected denial');}
$config=new Config('test',false,'test',[],'',3306,'','','','workforce','api','scope',300,staffInvitationsEnabled:true);
$pdo=new InvitationPDO();$database=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($database,$pdo);
$service=new Invitations($database,$config,new AuditLogger($database));
$admin=new AuthContext(1,1,'admin','admin@example.test','Admin','staff',['super_admin']);
foreach(['practitioner','clinic_admin','reception','accountant'] as $role)denies(fn()=>Invitations::authorize(new AuthContext(2,1,'x','x@example.test','X','staff',[$role])),'forbidden');
denies(fn()=>Invitations::authorize(new AuthContext(2,1,'x','x@example.test','X','client',['super_admin'])),'forbidden');
$invite=['id'=>1,'clinic_id'=>1,'invited_by'=>1,'recipient_email'=>'new@example.test','given_name'=>'New','family_name'=>'Staff','discipline'=>'Massage','location_id'=>1,'existing_user_id'=>null,'expires_at'=>gmdate('Y-m-d H:i:s',time()+300),'revoked_at'=>null,'accepted_at'=>null];
foreach(['expires_at'=>gmdate('Y-m-d H:i:s',time()-1),'revoked_at'=>gmdate('Y-m-d H:i:s'),'accepted_at'=>gmdate('Y-m-d H:i:s')] as $field=>$value)denies(fn()=>Invitations::usable(array_replace($invite,[$field=>$value])),'invitation_unavailable');
$body=['recipient_email'=>'new@example.test','given_name'=>'New','family_name'=>'Staff','discipline'=>'Massage','location_id'=>1];
denies(fn()=>$service->create($admin,$body+['role'=>'super_admin'],'test'),'validation_error');
$created=$service->create($admin,$body,'test');check(strlen($created['token'])===64);
$stored=array_values(array_filter($pdo->writes,fn($w)=>str_starts_with($w[0],'INSERT INTO staff_invitations')))[0][1];
check($stored[8]===hash('sha256',$created['token'])&&!in_array($created['token'],$stored,true));
$pdo->invite=$invite;$pdo->writes=[];
$identity=new VerifiedIdentity('entra-external-staff','https://trusted.test','subject','tenant');
denies(fn()=>$service->claim(1,new VerifiedIdentity('customer','https://trusted.test','subject','tenant'),['token'=>$created['token'],'claimant_name'=>'New Staff'],'test'),'staff_identity_required');
denies(fn()=>$service->claim(2,$identity,['token'=>$created['token'],'claimant_name'=>'New Staff'],'test'),'invitation_not_found');
$result=$service->claim(1,$identity,['token'=>$created['token'],'claimant_name'=>'New Staff'],'test');
check($result['status']==='pending'&&strlen($result['verification_code'])===12);
check(count(array_filter($pdo->writes,fn($w)=>str_contains($w[0],'staff_memberships')||str_contains($w[0],'INSERT INTO users')))===0);
check($service->claim(1,$identity,['token'=>$created['token'],'claimant_name'=>'New Staff'],'test')===$result);
denies(fn()=>$service->claim(1,new VerifiedIdentity('entra-external-staff','https://trusted.test','other-subject','tenant'),['token'=>$created['token'],'claimant_name'=>'New Staff'],'test'),'invitation_claimed');
denies(fn()=>$service->approve($admin,1,[],'test'),'recipient_verification_required');
denies(fn()=>$service->approve($admin,1,['recipient_verified'=>true,'verification_code'=>'wrong'],'test'),'recipient_verification_required');
$approval=['recipient_verified'=>true,'verification_code'=>$result['verification_code']];
$pdo->member=true;denies(fn()=>$service->approve($admin,1,$approval,'test'),'membership_exists');$pdo->member=false;
$pdo->identity=['id'=>5,'status'=>'inactive'];denies(fn()=>$service->approve($admin,1,$approval,'test'),'identity_inactive');$pdo->identity=false;
$pdo->duplicateEmail=true;denies(fn()=>$service->approve($admin,1,$approval,'test'),'email_already_exists');$pdo->duplicateEmail=false;
$before=$pdo->writes;$pdo->failAudit=true;
try{$service->approve($admin,1,$approval,'test');throw new RuntimeException('Expected failed audit');}catch(PDOException){}
check($pdo->writes===$before&&$pdo->invite['accepted_at']===null&&!$pdo->inTransaction());$pdo->failAudit=false;
$approved=$service->approve($admin,1,$approval,'test');check($approved['status']==='approved'&&$pdo->invite['accepted_at']!==null);
denies(fn()=>$service->approve($admin,1,$approval,'test'),'invitation_unavailable');
denies(fn()=>$service->revoke($admin,1,'test'),'invitation_unavailable');
$pdo->invite=$invite;$service->revoke($admin,1,'test');
denies(fn()=>$service->claim(1,$identity,['token'=>$created['token'],'claimant_name'=>'New Staff'],'test'),'invitation_unavailable');
check($pdo->rollbacks>0&&!$pdo->inTransaction());
$disabled=new Config('test',false,'test',[],'',3306,'','','','workforce','api','scope',300);
denies(fn()=>(new Invitations($database,$disabled,new AuditLogger($database)))->create($admin,$body,'test'),'staff_invitations_disabled');
echo "$checks staff invitation policy/transaction checks passed (PDO double).\n";
