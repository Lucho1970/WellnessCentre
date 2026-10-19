<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Auth\{AuthContext,ApplicationAdminContext,VerifiedIdentity};
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\{ApiException,Request,Response};
use Wellness\Service\AccessAudit;

final class AccessAuditPDO extends PDO
{
    public array $rows=[],$pending=[];
    public bool $transaction=false;
    public int $attempts=0,$failAt=0,$rollbacks=0;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(!str_starts_with($query,'INSERT INTO access_audit_events'))throw new RuntimeException('Unexpected audit SQL');
        return new AccessAuditStatement($this);
    }
    public function beginTransaction(): bool {$this->transaction=true;$this->pending=[];return true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function commit(): bool {$this->rows=[...$this->rows,...$this->pending];$this->pending=[];$this->transaction=false;return true;}
    public function rollBack(): bool {$this->pending=[];$this->transaction=false;$this->rollbacks++;return true;}
}
final class AccessAuditStatement extends PDOStatement
{
    public function __construct(private AccessAuditPDO $pdo) {}
    public function execute(?array $params=null): bool {
        if(++$this->pdo->attempts===$this->pdo->failAt)throw new RuntimeException('Sensitive database exception must not reach logs');
        $this->pdo->pending[]=$params;return true;
    }
}
$config=new Config('test',false,'test',[],'',3306,'','','','tenant','api','scope',300);
$make=function()use($config):array{
    $pdo=new AccessAuditPDO();$db=new Database($config);(new ReflectionProperty(Database::class,'connection'))->setValue($db,$pdo);
    return [new AccessAudit($db),$pdo];
};
$request=fn(string $path='/api/v1/clients',string $method='GET')=>new Request($method,$path,['q'=>'private-search','date_of_birth'=>'1990-01-01'],['authorization'=>'Bearer private-token','x-customer-session'=>'private-session'],['answers'=>['id'=>991]],'audit-test-correlation');
$actor=new AuthContext(7,1,'secret-subject','secret-email','secret-name','staff',['practitioner']);

if(($argv[1]??'')==='--response-probe'){
    [$audit,$pdo]=$make();$audit->actor($actor);$pdo->failAt=($argv[2]??'')==='fail'?1:0;
    ob_start();
    Response::beforeJson(function(array $data,int $status)use($audit,$request,$pdo,$argv):void{
        if(ob_get_length()!==0)throw new RuntimeException('Response escaped before auditing');
        if(($argv[2]??'')==='bad-json'&&($status!==500||$pdo->rows!==[]))throw new RuntimeException('Invalid JSON must not produce a success audit');
        $audit->response($request(),$data,$status,1);
    });
    try{Response::json(['data'=>['items'=>[['id'=>'12','display_name'=>($argv[2]??'')==='bad-json'?"\xB1":'private-patient']]]]);}
    catch(ApiException $error){Response::json(['error'=>['code'=>$error->errorCode]],$error->status);}
    catch(JsonException){Response::json(['error'=>['code'=>'internal_error']],500);}
}

$checks=0;
$check=function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
[$audit,$pdo]=$make();$audit->actor($actor);
$payload=['data'=>['items'=>[['id'=>'12','client_id'=>3,'display_name'=>'private-patient','answers'=>['id'=>999,'client_id'=>998],'definition'=>['id'=>997],'payload'=>['appointment_id'=>996]]],'has_more'=>true]];
$audit->response($request(),$payload,200,1);
$row=$pdo->rows[0];$meta=json_decode($row['metadata'],true,32,JSON_THROW_ON_ERROR);
$check($row['actor_type']==='staff'&&$row['actor_id']===7&&$row['clinic']===1,'Verified attribution');
$check($row['action']==='access.search'&&$row['correlation']==='audit-test-correlation','Search classification and correlation');
$check($meta===['resources'=>[['type'=>'client','id'=>12],['type'=>'client','id'=>3]]],'Only structural resource identifiers are retained');
foreach([
    ['/api/v1/admin/staff',['items'=>[['id'=>8]]],['type'=>'user','id'=>8]],
    ['/api/v1/profile/qualifications',[['id'=>8]],['type'=>'qualification','id'=>8]],
    ['/api/v1/forms/templates/4/history',['items'=>[['id'=>8]]],['type'=>'form_template','id'=>8]],
    ['/api/v1/admin/notifications',['items'=>[['id'=>8,'appointment_id'=>9,'recipient_address'=>'private-email']]],['type'=>'notification','id'=>8]],
    ['/api/v1/clients/1/merge-preview/2',['survivor'=>['id'=>1],'duplicate'=>['id'=>2]],['type'=>'client','id'=>2]],
    ['/api/v1/application/practitioners',['items'=>[['id'=>8,'clinics'=>[['clinic_id'=>2]]]]],['type'=>'global_practitioner','id'=>8]],
] as [$path,$body,$expected])$check(in_array($expected,AccessAudit::resources($body,AccessAudit::describe($request($path))['entity']),true),'Known response shape has typed resource coverage: '.$path);
$check(!str_contains(json_encode($row),'private-')&&!str_contains(json_encode($row),'1990'),'No query, credentials, demographics, answers or clinical content');
$check($payload['data']['items'][0]['id']==='12','Auditing does not alter the response contract');
$targeted=new Request('POST','/api/v1/clients/12',[],['user_id'=>99],['client_id'=>12,'practitioner_id'=>12,'user_id'=>999,'date_of_birth'=>'1990-01-01'],'targeted-audit');
$audit->response($targeted,['error'=>['code'=>'invalid_client']],422,1);
$last=end($pdo->rows);$targets=json_decode($last['metadata'],true)['attempted_resources'];
$check($last['actor_id']===7&&count($targets)===3&&in_array(['type'=>'practitioner','id'=>12],$targets,true)&&in_array(['type'=>'client','id'=>12],$targets,true),'Numeric attempted targets neither collide across types nor replace verified attribution');
$audit->response($request('/api/v1/clients','POST'),['error'=>['code'=>'possible_duplicate','fields'=>['candidates'=>[['id'=>18,'email'=>'private-email']]]]],409,1);
$check(json_decode(end($pdo->rows)['metadata'],true)['resources']===[['type'=>'client','id'=>18]],'Duplicate review candidates in validation responses are audited');
$audit->response($request('/api/v1/clients','POST'),['error'=>['code'=>'client_registration_review']],409,1);
$check(end($pdo->rows)['outcome']==='denied'&&json_decode(end($pdo->rows)['metadata'],true)['resources']===[],'Registration review is an authorization denial without disclosed identities');
$audit->response($request(),['data'=>['items'=>[]]],200,1);
$check(json_decode(end($pdo->rows)['metadata'],true)===['resources'=>[]],'Empty searches are still logged');
foreach([401,403,404,422] as $status){
    $audit->response($request('/api/v1/booking-clients/999/address'),['error'=>['code'=>$status===422?'invalid_client':'forbidden','message'=>'private-detail','fields'=>['email'=>'private-email']]],$status,1);
    $last=end($pdo->rows);$check($last['outcome']==='denied'&&$last['status']===$status,'Denied or concealed access classified');
    $meta=json_decode($last['metadata'],true);$check($meta['attempted_resources']===[['type'=>'client','id'=>999]]&&$meta['resources']===[],'Attempted IDs are not disclosed records');
}
$audit->response($request('/api/v1/forms/tasks/88'),['data'=>['id'=>88,'submission_id'=>77,'answers'=>['id'=>666],'amendments'=>[['id'=>5,'answers'=>['id'=>665]]]]],200,1);
$refs=json_decode(end($pdo->rows)['metadata'],true)['resources'];
$check(in_array(['type'=>'form_submission','id'=>77],$refs,true)&&in_array(['type'=>'form_amendment','id'=>5],$refs,true)&&!str_contains(json_encode($refs),'666'),'Forms track submissions/amendments, never answers');
$audit->response($request('/api/v1/travel-enquiries/2'),['data'=>['id'=>2,'messages'=>[['id'=>9,'body'=>'private-message']]]],200,1);
$check(in_array(['type'=>'travel_enquiry_message','id'=>9],json_decode(end($pdo->rows)['metadata'],true)['resources'],true),'Message reads identify messages without bodies');
$audit->response($request('/api/v1/dashboard'),['data'=>['definitions'=>[['id'=>'private-widget-key','dataProjection'=>'next_appointment']],'values'=>['private-widget-key'=>['id'=>44,'client_name'=>'private-name']],'time_off_follow_ups'=>['appointments'=>[['id'=>45]]]]],200,1);
$refs=json_decode(end($pdo->rows)['metadata'],true)['resources'];
$check(in_array(['type'=>'appointment','id'=>44],$refs,true)&&in_array(['type'=>'appointment','id'=>45],$refs,true),'Dashboard projections and follow-up lists covered');
$audit->response($request('/api/v1/practitioner/next-onsite'),['data'=>null],200,1);
$check(end($pdo->rows)['outcome']==='success','Nullable read responses remain valid');
$many=array_map(fn(int $id)=>['id'=>$id,'kind'=>$id===1001?'time_off':'appointment'],range(1,1001));
$before=count($pdo->rows);$audit->response($request('/api/v1/practitioner/calendar'),['data'=>$many],200,1);$parts=array_slice($pdo->rows,$before);
$check(count($parts)===6&&count(array_unique(array_column($parts,'event')))===1,'Large calendar grouped into durable chunks');
$all=[];foreach($parts as $part)$all=[...$all,...json_decode($part['metadata'],true)['resources']];
$check(count($all)===1001&&end($all)===['type'=>'time_off','id'=>1001],'No record truncation; mixed calendar types retained');
[$anonymous,$anonPdo]=$make();
$anonymous->response($request('/api/v1/public/services'),['data'=>['items'=>[['id'=>1]]]],200,1);
$check($anonPdo->rows===[],'Published anonymous reads are excluded');
$anonymous->response($request('/api/v1/clients/private-token'),['error'=>['code'=>'unauthorized']],401,null);
$last=end($anonPdo->rows);$check($last['actor_type']==='anonymous'&&$last['actor_id']===null&&$last['clinic']===null,'Rejected credentials never become verified attribution');
$check(!str_contains($last['route'],'private')&&$last['route']==='/api/v1/clients/:value','Raw path fragments are excluded');
$check(AccessAudit::describe($request('/api/v1/clients/00012'))['targets']===[['type'=>'client','id'=>12]],'Leading-zero route IDs match server numeric interpretation');
$anonymous->response(null,['error'=>['code'=>'invalid_json']],400,null);
$check(end($anonPdo->rows)['route']==='unparsed','Capture failures are recorded without parsing unsafe input');
$anonymous->customerIdentity(22,1);$anonymous->response($request('/api/v1/customer/auth/me'),['data'=>['review_code'=>'private-review']],200,1);
$check(end($anonPdo->rows)['actor_type']==='customer_identity'&&end($anonPdo->rows)['actor_id']===22,'Verified unlinked customer attribution');
$anonymous->actor(new AuthContext(3,1,'','','','client',[]));$anonymous->response($request('/api/v1/customer/profile'),['data'=>['phone'=>'private-phone']],200,1);
$check(json_decode(end($anonPdo->rows)['metadata'],true)['resources']===[['type'=>'client','id'=>3]],'Own profile has a record reference even without response ID');
$anonymous->applicationActor(new ApplicationAdminContext(6,new VerifiedIdentity('test','issuer','subject','tenant',[]),'private-name','private-email'));
$anonymous->response($request('/api/v1/application/clinics/2/practitioner-enquiries'),['data'=>['items'=>[['id'=>8]]]],200,null);
$check(end($anonPdo->rows)['actor_type']==='application_admin'&&end($anonPdo->rows)['clinic']===null,'Global actor does not masquerade as a clinic user');
[$broken,$badPdo]=$make();$broken->actor($actor);$badPdo->failAt=2;
try{$broken->response($request('/api/v1/appointments'),['data'=>$many],200,1);throw new RuntimeException('Expected fail-closed');}
catch(ApiException $error){$check($error->status===503&&$error->errorCode==='access_audit_unavailable','Failed chunk blocks disclosure');}
$check($badPdo->rows===[]&&!$badPdo->inTransaction(),'Failed chunk rolls back every audit part');
[$open,$openPdo]=$make();$open->actor($actor);$openPdo->beginTransaction();
try{$open->response($request(),['data'=>['items'=>[]]],200,1);throw new RuntimeException('Expected unfinished-transaction refusal');}
catch(ApiException $error){$check($error->status===503&&!$openPdo->inTransaction(),'An unfinished business transaction cannot produce a successful audited disclosure');}
$badPdo->beginTransaction();$badPdo->pending[]=['uncommitted_business_write'=>true];$badPdo->failAt=0;
$broken->response($request('/api/v1/clients/4'),['error'=>['code'=>'forbidden']],403,1);
$check(count($badPdo->rows)===1&&$badPdo->rows[0]['outcome']==='denied'&&$badPdo->rollbacks===2,'Denial survives rejected business transaction rollback');
$badPdo->failAt=$badPdo->attempts+1;$broken->response($request(),['error'=>['code'=>'forbidden']],403,1);
$check(count($badPdo->rows)===1,'Failed denial logging preserves denial, never success');
foreach(['success','fail','bad-json'] as $mode){
    $process=proc_open([PHP_BINARY,__FILE__,'--response-probe',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start response audit probe');
    fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $check(proc_close($process)===0,'Response audit probe exits cleanly');
    $decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    $check($mode==='success'?($decoded['data']['items'][0]['id']===12):($decoded===['error'=>['code'=>$mode==='fail'?'access_audit_unavailable':'internal_error']]&&!str_contains($body,'private-patient')),'Audit must succeed before sensitive bytes are emitted');
    $check(!str_contains($errors,'Sensitive database')&&!str_contains($errors,'private-'),'Operational fallback has no payload or exception text');
}
echo "Access audit policy/response checks: $checks passed.\n";
