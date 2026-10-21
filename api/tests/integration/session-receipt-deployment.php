<?php
declare(strict_types=1);
// Rehearse the exact phpMyAdmin files on a disposable localhost schema, never .env.
require dirname(__DIR__,2).'/vendor/autoload.php';
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Explicit synthetic database creation permission is required.');
$port=(int)(getenv('RECURRING_TEST_PORT')?:13317);
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='wellness_receipt_release_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$name`");
$root=dirname(__DIR__,2);$schema=file_get_contents($root.'/database/schema.sql');$schema=substr($schema,0,strpos($schema,'-- Apply once after the existing invoice/payment foundations. Default-off feature.'));$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema));
foreach(['005_customer_onboarding.sql','039_separate_clinic_portals.sql','040_application_administration.sql','042_global_practitioners.sql']as$file)$pdo->exec(file_get_contents($root.'/database/migrations/'.$file));
$sets=static function(string $file)use($pdo):array{$q=$pdo->query(file_get_contents($file));$rows=[];do{if($q->columnCount())$rows[]=$q->fetchAll();}while($q->nextRowset());$q->closeCursor();return $rows;};
$checks=0;$assert=static function(bool $ok)use(&$checks):void{if(!$ok)throw new RuntimeException('Receipt deployment rehearsal failed.');$checks++;};
$before=$sets($root.'/database/maintenance/session-receipts-preflight.sql');
$assert($before[0][0]['selected_database']===$name);
$assert(count($before[1])===13&&array_unique(array_column($before[1],'prerequisite'))===['OK']);
$assert(count($before[2])===4&&array_unique(array_column($before[2],'appointment_prerequisite'))===['OK']);
$assert((int)$before[3][0]['receipt_tables_present']===0);
$pdo->exec(file_get_contents($root.'/database/migrations/043_session_receipts.sql'));
$after=$sets($root.'/database/maintenance/session-receipts-postflight.sql');
$assert(count($after[0])===3&&array_unique(array_column($after[0],'engine'))===['InnoDB']);
$assert(count($after[1])===1&&str_contains(strtoupper($after[1][0]['extra']),'STORED GENERATED'));
$assert(count($after[2])===3&&array_unique(array_map('intval',array_column($after[2],'non_unique'))) === [0]);
$again=$sets($root.'/database/maintenance/session-receipts-preflight.sql');$assert((int)$again[3][0]['receipt_tables_present']===3);
echo "$checks receipt deployment SQL checks passed; synthetic database retained: $name\n";
