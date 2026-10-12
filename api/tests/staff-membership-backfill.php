<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\StaffMembershipBackfill;
$tenant='11111111-1111-4111-8111-111111111111';
$row=['user_id'=>7,'clinic_id'=>1,'user_type'=>'staff','status'=>'active','clinic_status'=>'active','tenant_id'=>$tenant,'provider_subject'=>'22222222-2222-4222-8222-222222222222'];
$checks=0;
function mustReject(callable $f): void {global $checks;try{$f();}catch(RuntimeException){$checks++;return;}throw new RuntimeException('Unsafe candidate accepted');}
$candidate=StaffMembershipBackfill::candidate([$row],$tenant);
if($candidate['user_id']!==7||$candidate['subject']!==$row['provider_subject'])throw new RuntimeException('Mapping changed');$checks++;
mustReject(fn()=>StaffMembershipBackfill::candidate([],$tenant));
mustReject(fn()=>StaffMembershipBackfill::candidate([$row,$row],$tenant));
foreach(['status'=>'inactive','clinic_status'=>'inactive','user_type'=>'client','tenant_id'=>'other','provider_subject'=>''] as $field=>$value){$bad=$row;$bad[$field]=$value;mustReject(fn()=>StaffMembershipBackfill::candidate([$bad],$tenant));}
echo "$checks backfill candidate checks passed.\n";

final class BackfillPDO extends PDO {
    public array $source=[],$identities=[],$members=[],$saved=[];
    public bool $transaction=false;
    public int $last=0;
    public function __construct(){}
    public function beginTransaction(): bool {$this->saved=[$this->identities,$this->members];$this->transaction=true;return true;}
    public function commit(): bool {$this->transaction=false;return true;}
    public function rollBack(): bool {[$this->identities,$this->members]=$this->saved;$this->transaction=false;return true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function lastInsertId(?string $name=null): string|false {return (string)$this->last;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new BackfillStatement($this,$query);}
}
final class BackfillStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private BackfillPDO $db,private string $sql){}
    public function execute(?array $params=null): bool {
        $p=$params??[];$this->rows=[];
        if(str_contains($this->sql,'FROM users'))$this->rows=$this->db->source[$p['id']]??[];
        elseif(str_contains($this->sql,'SELECT id,status FROM product_identities'))$this->rows=array_values(array_filter($this->db->identities,fn($i)=>$i['issuer']===$p['issuer']&&$i['subject']===$p['subject']));
        elseif(str_contains($this->sql,'SELECT identity_id'))$this->rows=array_values(array_filter($this->db->members,fn($m)=>$m['local_user_id']===$p['user']||($m['identity_id']===$p['identity']&&$m['clinic_id']===$p['clinic'])));
        elseif(str_contains($this->sql,'INSERT INTO product_identities')){$this->db->last++;$this->db->identities[]=['id'=>$this->db->last,'status'=>'active']+$p;}
        elseif(str_contains($this->sql,'INSERT INTO staff_memberships'))$this->db->members[]=['identity_id'=>$p['identity'],'clinic_id'=>$p['clinic'],'local_user_id'=>$p['user'],'status'=>'active'];
        else throw new RuntimeException('Unexpected query');
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->rows[0]??false;}
}
function verifyResult(bool $ok): void {if(!$ok)throw new RuntimeException('Backfill transaction check failed');}
$pdo=new BackfillPDO();$pdo->source[7]=[$row];$backfill=new StaffMembershipBackfill($pdo,$tenant);
verifyResult($backfill->run([7])[0]['action']==='would_import'&&$pdo->members===[]&&$pdo->identities===[]);
$review=$backfill->run([7]);
mustReject(fn()=>$backfill->run([7],true,[7=>str_repeat('0',64)]));verifyResult($pdo->members===[]&&$pdo->identities===[]);
mustReject(fn()=>$backfill->run([7,8],true));verifyResult($pdo->members===[]&&$pdo->identities===[]&&!$pdo->inTransaction());
verifyResult($backfill->run([7],true,[7=>$review[0]['binding_hash']])[0]['action']==='imported'&&count($pdo->members)===1&&count($pdo->identities)===1);
verifyResult($backfill->run([7],true)[0]['action']==='already_imported'&&count($pdo->members)===1);
$pdo->members[0]['status']='revoked';mustReject(fn()=>$backfill->run([7],true));verifyResult($pdo->members[0]['status']==='revoked');
$pdo->members[0]['status']='active';$pdo->identities[0]['status']='inactive';mustReject(fn()=>$backfill->run([7],true));verifyResult($pdo->identities[0]['status']==='inactive');
echo "Dry-run, atomic rollback, idempotency and no-reactivation tests passed with a PDO double.\n";
