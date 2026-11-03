<?php
declare(strict_types=1);
// Included by the explicit disposable SQL suite only. Tokens travel over pipes,
// never process arguments, files, test output or shell interpolation.
$workers=[];
$spawn=static function(array $input)use(&$workers,$name,$f):array {
    $process=proc_open([PHP_BINARY,dirname(__DIR__).'/integration/browser-session-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start disposable worker.');
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    fwrite($pipes[0],json_encode($input+['database'=>$name,'now'=>$f->now,'jwks'=>$f->jwks],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
    $worker=['process'=>$process,'pipes'=>$pipes];$workers[]=$worker;return $worker;
};
$line=static function(array $worker):string {
    $until=microtime(true)+12;
    do { $line=fgets($worker['pipes'][1]); if($line!==false)return trim($line);usleep(10000); } while(microtime(true)<$until && proc_get_status($worker['process'])['running']);
    throw new RuntimeException('Disposable worker timed out; no credential diagnostics emitted.');
};
$go=static function(array $worker):void { fwrite($worker['pipes'][0],"go\n");fflush($worker['pipes'][0]); };
$waits=static function()use($pdo):bool {
    $until=microtime(true)+8;
    // MySQL refreshes this snapshot only after >0.1s since its last read.
    // A 20ms poll can pin an empty snapshot for the entire observation window.
    // https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/innodb-information-schema-internal-data.html
    do { usleep(200000);if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.innodb_lock_waits')->fetchColumn()>0)return true; }while(microtime(true)<$until);
    return false;
};
try {
    $pdo->prepare('UPDATE session_test_authority SET active=1,effects=0 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    [$grant,$api]=$begin($s,$p);$cookie=$grant->cookieValue();$csrf=$grant->metadata['csrf'];
    $credentials=['bearer'=>$api,'cookie'=>$cookie,'csrf'=>$csrf];$principal=$p->principalHash($f->claims($p));
    $writer=$spawn($credentials+['mode'=>'protect']);SessionTest::check($line($writer)==='starting' && $line($writer)==='locked','Effect acquired shared epoch/authority locks');
    $departure=$spawn(['mode'=>'revoke','principal'=>$principal]);SessionTest::check($line($departure)==='starting','Departure contends with running effect');
    if(!$waits())throw new RuntimeException('Departure lock observation failed; next worker classification: '.$line($departure));
    SessionTest::check(true,'Observed departure waiting on real InnoDB lock');
    $go($writer);SessionTest::check($line($writer)==='status:200','Authorized effect commits before waiting departure');
    SessionTest::check($line($departure)==='locked','Departure acquires epoch after effect');$go($departure);SessionTest::check($line($departure)==='status:200','Departure committed');
    SessionTest::deny(fn()=>$s->protect($api,$cookie,$csrf,true,$cid,$effect),401,'Retained session cannot write after departure');
    SessionTest::check((int)$pdo->query("SELECT effects FROM session_test_authority WHERE scope_hash='".$p->scopeHash()."'")->fetchColumn()===1,'No stale effect after revocation');
    $pdo->prepare('UPDATE session_test_authority SET active=1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    [$grant,$api]=$begin($s,$p);$credentials=['bearer'=>$api,'cookie'=>$grant->cookieValue(),'csrf'=>$grant->metadata['csrf']];
    $departure=$spawn(['mode'=>'revoke','principal'=>$principal]);SessionTest::check($line($departure)==='starting' && $line($departure)==='locked','Departure owns epoch first');
    $writer=$spawn($credentials+['mode'=>'protect']);SessionTest::check($line($writer)==='starting','Write starts behind departure');
    if(!$waits())throw new RuntimeException('Writer lock observation failed; next worker classification: '.$line($writer));
    SessionTest::check(true,'Observed protected writer waiting on real InnoDB lock');
    $go($departure);SessionTest::check($line($departure)==='status:200','Departure committed ahead of write');
    SessionTest::check($line($writer)==='status:401','Waiting write rechecks epoch and never executes effect');
    SessionTest::check((int)$pdo->query("SELECT effects FROM session_test_authority WHERE scope_hash='".$p->scopeHash()."'")->fetchColumn()===1,'Denied contender did not commit');
    $pdo->prepare('UPDATE session_test_authority SET active=1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    $c=$s->challenge(hash('sha256','concurrent-login'));$api=$f->sign($f->claims($p));$proof=$f->sign($f->claims($p,$c->metadata['nonce'],true));
    $count=(int)$pdo->query('SELECT COUNT(*) FROM browser_sessions')->fetchColumn();
    $input=['mode'=>'start','bearer'=>$api,'proof'=>$proof,'challenge'=>$c->cookieValue()];$a=$spawn($input);$b=$spawn($input);
    SessionTest::check($line($a)==='starting' && $line($b)==='starting','Duplicate starts launched');
    $results=[$line($a),$line($b)];sort($results);SessionTest::check($results===['status:200','status:401'],'Only one concurrent proof can commit');
    SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM browser_sessions')->fetchColumn()===$count+1,'No duplicate session');
    [$grant,$api]=$begin($s,$p);$cookie=$grant->cookieValue();$csrf=$grant->metadata['csrf'];$c=$s->actionChallenge($api,$cookie,$csrf,$cid);
    $proof=$s->actionProof($api,$cookie,$csrf,$f->sign($f->claims($p,$c->metadata['nonce'],true)),$c->cookieValue(),$cid);
    $input=['mode'=>'all','bearer'=>$api,'cookie'=>$cookie,'csrf'=>$csrf,'action'=>$proof['action_proof']];$a=$spawn($input);$b=$spawn($input);
    SessionTest::check($line($a)==='starting' && $line($b)==='starting','Concurrent action-proof consumers launched');
    $results=[$line($a),$line($b)];sort($results);SessionTest::check($results===['status:200','status:401'],'Single-use action cannot race');
} finally {
    foreach($workers as $worker) {
        if(proc_get_status($worker['process'])['running'])proc_terminate($worker['process']);
        foreach($worker['pipes'] as $pipe)fclose($pipe);proc_close($worker['process']);
    }
}
