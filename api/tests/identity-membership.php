<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Wellness\Auth\EntraAuthenticator;
use Wellness\Auth\VerifiedIdentity;
use Wellness\Auth\StaffMembershipResolver;
use Wellness\ClinicContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

$checks=0;
function expect(bool $ok): void { global $checks; if(!$ok)throw new RuntimeException('Identity assertion failed'); $checks++; }
function denies(callable $f,string $code): void {try{$f();}catch(ApiException $e){expect($e->errorCode===$code);return;}throw new RuntimeException('Expected denial');}
final class MemberPDO extends PDO {
    public array $params=[];
    public string $sql='';
    public array|false $row=false;
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->sql=$query;return new MemberStatement($this);}
}
final class MemberStatement extends PDOStatement {
    public function __construct(private MemberPDO $db){}
    public function execute(?array $params=null): bool {$this->db->params=$params??[];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {return $this->db->row;}
}
$config=new Config('test',false,'test',[],'',3306,'','','','staff-tenant','staff-api','access_as_user',300,clinicHostMap:['a.test'=>1,'b.test'=>2]);
$pdo=new MemberPDO();$db=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($db,$pdo);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
if(!$key)throw new RuntimeException('RSA unavailable');
$parts=openssl_pkey_get_details($key)['rsa'];
$jwk=['kty'=>'RSA','kid'=>'test','n'=>JWT::urlsafeB64Encode($parts['n']),'e'=>JWT::urlsafeB64Encode($parts['e'])];
$auth=new EntraAuthenticator($config,$db,fn()=>['keys'=>[$jwk]]);
$claims=['iss'=>'https://login.microsoftonline.com/staff-tenant/v2.0','tid'=>'staff-tenant','aud'=>'staff-api','oid'=>'object-A','sub'=>'different-sub','scp'=>'access_as_user','roles'=>['Wellness.Practitioner','Unknown.Role'],'iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
$sign=fn(array $c)=>JWT::encode($c,$key,'RS256','test');
$identity=$auth->verify($sign($claims));
expect($identity->subject==='object-A'&&$identity->adapter==='entra-workforce'&&$identity->directoryRoles===['practitioner']);
expect($pdo->sql===''); // verification does not provision/read a local user
denies(fn()=>$auth->verify(null),'unauthorized');
foreach(['iss'=>'https://other.test','tid'=>'other','aud'=>'other','scp'=>'other','exp'=>time()-180] as $field=>$value){$bad=$claims;$bad[$field]=$value;denies(fn()=>$auth->verify($sign($bad)),match($field){'scp'=>'missing_scope','exp'=>'token_expired',default=>'invalid_token_claims'});}
$pdo->row=['id'=>7,'clinic_id'=>1,'email'=>'staff@example.test','display_name'=>'Staff','user_type'=>'staff','status'=>'active','roles'=>'clinic_admin,practitioner','permissions'=>'approve_onsite_service_area'];
$legacy=$auth->authenticate($sign($claims));expect($legacy->userId===7&&$legacy->roles===['practitioner']);
expect(str_contains($pdo->sql,'identity_links')&&!str_contains($pdo->sql,'staff_memberships'));
$resolver=new StaffMembershipResolver($db);$clinic=ClinicContext::forHost($config,'a.test');
$actor=$resolver->resolve($identity,$clinic);expect($actor->userId===7&&$actor->roles===['practitioner']);
expect($pdo->params===['adapter'=>'entra-workforce','issuer'=>$claims['iss'],'subject'=>'object-A','clinic'=>1]);
foreach(["i.status='active'","m.status='active'","c.status='active'","u.status='active'","u.user_type='staff'",'u.clinic_id=m.clinic_id'] as $scope)expect(str_contains($pdo->sql,$scope));
denies(fn()=>$resolver->resolve(new VerifiedIdentity('customer','https://other.test','object-A',''),$clinic),'staff_identity_required');
denies(fn()=>$resolver->resolve($identity,ClinicContext::forHost($config,'b.test')),'clinic_access_denied');
$pdo->row=false;denies(fn()=>$resolver->resolve($identity,$clinic),'membership_required');
echo "$checks identity/membership checks passed.\n";
