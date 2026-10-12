<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
\Wellness\Config::loadEnvFile(dirname(__DIR__).'/.env');
try{
    $options=getopt('', ['user-ids:','apply','expected-count:']);
    $raw=$options['user-ids']??'';
    if(!is_string($raw)||!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D',$raw))throw new RuntimeException('Usage: --user-ids=ID[,ID] [--apply --expected-count=N]');
    $ids=array_map('intval',explode(',',$raw));$apply=isset($options['apply']);
    if($apply && (string)($options['expected-count']??'')!==(string)count($ids))throw new RuntimeException('Apply requires an expected count matching the reviewed user selection.');
    $config=\Wellness\Config::fromEnvironment();
    $report=(new \Wellness\Service\StaffMembershipBackfill((new \Wellness\Database($config))->connection(),$config->entraTenantId))->run($ids,$apply);
    echo json_encode(['mode'=>$apply?'apply':'dry-run','accounts'=>$report],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Backfill stopped: '.($e instanceof PDOException?'Database conflict or schema error; no transaction changes retained.':$e->getMessage()).PHP_EOL);exit(1);}
