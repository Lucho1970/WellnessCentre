<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\ProviderEvidenceCsv as C;
use Wellness\Http\ApiException;
$checks=0;$ok=function(bool $condition)use(&$checks):void{if(!$condition)throw new RuntimeException('Provider evidence assertion failed');$checks++;};
$header="type,reference,sender_did,destination,occurred_at,currency,amount\n";
$charge='charge,000123,4165550100,+14165550101,2026-01-01 12:00:00,USD,0.0175';
$rows=C::parse("\xEF\xBB\xBF".$header.$charge."\nstop,stop-1,4165550100,+14165550101,2026-01-02 12:00:00,,\n");
$ok($rows[0]['reference']==='000123'&&$rows[0]['amount_micros']===17500);
$ok(!str_contains(json_encode($rows),'+14165550101')&&strlen($rows[1]['number_hash'])===64);
$ok(C::cents(17500,1370000)===3&&C::cents(0,1370000)===0&&C::cents(10000,1000000)===1);
$ok(C::cents(1000000000000,1000000)===100000000&&C::cents(999999999999,1)===100);
for($i=0;$i<100;$i++){$a=random_int(0,100000000);$r=random_int(1,10000000);$ok(C::cents($a,$r)===intdiv($a*$r+9999999999,10000000000));}
$deny=function(callable $work)use($ok):void{try{$work();}catch(ApiException $e){$ok($e->status===422);return;}throw new RuntimeException('Unsafe evidence accepted');};
foreach([$header.$charge."\n".$charge,$header.str_replace('charge,','start,',$charge),$header.str_replace('USD,0.0175','USD,-1',$charge),$header.str_replace('USD,0.0175','USD,1e-2',$charge),$header.str_replace('USD,0.0175','EUR,1',$charge),$header.str_replace('+14165550101','+12025550101',$charge),str_replace('amount','message',$header).$charge,$header.str_replace('000123','=BAD',$charge),$header.str_repeat($charge."\n",501),$header.'stop,x,4165550100,+14165550101,2026-01-01 12:00:00,CAD,0'] as $csv)$deny(fn()=>C::parse($csv));
$deny(fn()=>C::cents(1000000000000,100000000));$deny(fn()=>C::decimal('0.0000001',100000000));
echo "$checks normalized provider evidence/decimal checks passed.\n";
