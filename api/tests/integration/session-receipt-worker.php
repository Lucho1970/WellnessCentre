<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Service\{AuditLogger,SessionReceiptService};
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true'||!preg_match('/^wellness_recurring_test_[a-f0-9]{12}$/D',$argv[2]??''))throw new RuntimeException('Only explicitly permitted synthetic localhost databases are accepted.');
$port=(int)$argv[1];if($port<1024||$port>65535)throw new RuntimeException('Invalid local port.');
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$argv[2],'root','','workforce','api','scope',300);$database=new Database($config);$_ENV['SESSION_RECEIPTS_ENABLED']='true';
$service=new SessionReceiptService($database,new AuditLogger($database));$actor=new AuthContext(2,1,'','','','staff',['practitioner']);
echo json_encode($service->issue($actor,90002,json_decode(base64_decode($argv[3]),true,32,JSON_THROW_ON_ERROR),'receipt-race-test'),JSON_THROW_ON_ERROR);
