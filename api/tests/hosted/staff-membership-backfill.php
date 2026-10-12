<?php
declare(strict_types=1);

// Temporary manual upload, retaining this filename, to Willow's public /api directory.
ini_set('display_errors','0');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$escape=static fn(string $s): string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$page=static function(string $body): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff membership backfill</title><style>body{font:16px system-ui;max-width:45rem;margin:2rem auto;padding:1rem;line-height:1.5}input,button{font:inherit;padding:.5rem}label{display:block;margin:1rem 0}pre{white-space:pre-wrap}</style><h1>Staff membership backfill</h1>'.$body.'</html>';
};
$method=$_SERVER['REQUEST_METHOD']??'';
if(!in_array($method,['GET','POST'],true)){http_response_code(405);exit;}
$https=($_SERVER['HTTPS']??'')==='on'||(string)($_SERVER['SERVER_PORT']??'')==='443'
    ||strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]))==='https';
if(!$https){http_response_code(403);exit;}
$root=dirname(__DIR__,3).'/wellness-api';
if(!is_file($root.'/vendor/autoload.php')||!is_file($root.'/.env')){http_response_code(404);exit;}
require $root.'/vendor/autoload.php';
\Wellness\Config::loadEnvFile($root.'/.env');
$env=static fn(string $key): string=>trim((string)($_ENV[$key]??getenv($key)?:''));
if(!filter_var($env('HOSTED_STAFF_BACKFILL_ENABLED'),FILTER_VALIDATE_BOOL)){http_response_code(404);exit;}
$secret=$env('HOSTED_STAFF_BACKFILL_SECRET');
if(strlen($secret)<32){http_response_code(503);$page('<p>Configure the private temporary secret (at least 32 random characters).</p>');exit;}
$secretField='<label>Temporary backfill secret <input type="password" name="secret" autocomplete="off" required></label>';
$dryForm='<form method="post">'.$secretField.'<input type="hidden" name="action" value="dry-run"><button>Run dry-run for user 2</button></form>';
if($method==='GET'){$page('<p>This temporary runner is limited to Esther’s existing local user ID <strong>2</strong>. Dry-run does not change the database or login. Do not enable membership login yet.</p>'.$dryForm);exit;}
$provided=is_string($_POST['secret']??null)?$_POST['secret']:'';
if(!hash_equals($secret,$provided)){http_response_code(404);exit;}
$action=$_POST['action']??'';
if(!in_array($action,['dry-run','apply'],true)){http_response_code(400);exit;}
$handle=null;
try{
    $config=\Wellness\Config::fromEnvironment();
    if(filter_var($env('STAFF_MEMBERSHIP_PILOT_ENABLED'),FILTER_VALIDATE_BOOL))throw new RuntimeException('Disable staff membership pilot login before using this runner.');
    $service=new \Wellness\Service\StaffMembershipBackfill((new \Wellness\Database($config))->connection(),$config->entraTenantId);
    $stateDir=$root.'/var/staff-backfill';
    if($action==='dry-run'){
        $report=$service->run([2]);
        if(!is_dir($stateDir)&&!mkdir($stateDir,0700,true)&&!is_dir($stateDir))throw new RuntimeException('Cannot create private review storage.');
        // Remove expired review files to bound temporary state growth.
        foreach(glob($stateDir.'/*.json')?:[] as $old)if(filemtime($old)<time()-900)unlink($old);
        $token=bin2hex(random_bytes(32));$path=$stateDir.'/'.hash('sha256',$token).'.json';
        $state=['expires'=>time()+900,'used'=>false,'binding'=>$report[0]['binding_hash']];
        if(file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cannot store private review.');
        chmod($path,0600);
        unset($report[0]['binding_hash']);
        $body='<h2>Dry-run: no database changes</h2><pre>'.$escape(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)).'</pre>';
        if(filter_var($env('HOSTED_STAFF_BACKFILL_APPLY_ENABLED'),FILTER_VALIDATE_BOOL)){
            $body.='<p>Review this report and keep membership login disabled. Applying inserts the reviewed identity and membership only. This review expires in 15 minutes.</p><form method="post">'.$secretField
                .'<input type="hidden" name="action" value="apply"><input type="hidden" name="review" value="'.$token.'">'
                .'<label><input type="checkbox" name="backup" value="yes" required> I have a database backup and have verified user 2 is Esther.</label>'
                .'<label>Type IMPORT USER 2 <input name="confirmation" autocomplete="off" required></label><button>Apply reviewed backfill for user 2</button></form>';
        }else $body.='<p>Apply is disabled. Share the report for review first. After review, set HOSTED_STAFF_BACKFILL_APPLY_ENABLED=true and run a new dry-run.</p>'.$dryForm;
        $page($body);exit;
    }
    if(!filter_var($env('HOSTED_STAFF_BACKFILL_APPLY_ENABLED'),FILTER_VALIDATE_BOOL))throw new RuntimeException('Apply is disabled.');
    if(($_POST['backup']??'')!=='yes'||($_POST['confirmation']??'')!=='IMPORT USER 2')throw new RuntimeException('Backup acknowledgement and exact confirmation are required.');
    $token=$_POST['review']??'';
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Run a new dry-run.');
    $path=$stateDir.'/'.hash('sha256',$token).'.json';
    $handle=@fopen($path,'r+');
    if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Review is unavailable; run a new dry-run.');
    $state=json_decode(stream_get_contents($handle),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($state)||($state['used']??true)||($state['expires']??0)<time()||!is_string($state['binding']??null))throw new RuntimeException('Review has expired or was used; run a new dry-run.');
    // Consume before attempting writes; failures require a fresh review.
    $state['used']=true;$encoded=json_encode($state,JSON_THROW_ON_ERROR);rewind($handle);
    if(!ftruncate($handle,0)||fwrite($handle,$encoded)!==strlen($encoded)||!fflush($handle))throw new RuntimeException('Cannot consume review safely.');
    $report=$service->run([2],true,[2=>$state['binding']]);unset($report[0]['binding_hash']);
    $page('<h2>Backfill completed</h2><pre>'.$escape(json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)).'</pre><p>Membership login is still disabled. Disable this runner and remove its public file after review.</p>');
}catch(Throwable $error){
    http_response_code(409);
    $message=$error instanceof PDOException?'Database conflict or schema error; the backfill transaction was not committed.':($error instanceof RuntimeException&&!$error instanceof JsonException?$error->getMessage():'Runner unavailable; verify deployed files and private review storage.');
    $page('<h2>Backfill stopped</h2><p>'.$escape($message).'</p>'.$dryForm);
}finally{if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}
