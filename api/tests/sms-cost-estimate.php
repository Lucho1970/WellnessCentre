<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\SmsCostEstimate as E;
$checks=0;$check=function(bool $v)use(&$checks){if(!$v)throw new RuntimeException('SMS estimate assertion failed');$checks++;};
$check(E::estimate(str_repeat('a',160),1250)===['segments'=>1,'estimated_cents'=>1]);
$check(E::estimate(str_repeat('^',160),1250)===['segments'=>3,'estimated_cents'=>1]);
$check(E::estimate(str_repeat('`',71),10000)===['segments'=>2,'estimated_cents'=>2]);
$check(E::estimate('Hello',10000,1)['estimated_cents']===2);
$check(E::estimate(str_repeat('{',81),100000000,100000000)['estimated_cents']===30000);
foreach([['',1],['Unicode é',1],[str_repeat('x',161),1],['OK',0],['OK',100000001]] as $args){try{E::estimate(...$args);throw new RuntimeException('Invalid estimate accepted');}catch(InvalidArgumentException){$check(true);}}
echo "$checks SMS cost estimate checks passed.\n";
