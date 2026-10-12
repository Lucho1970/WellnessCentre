<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Api;
$format=new ReflectionMethod(Api::class,'failureLog');
$checks=0;
$assert=function(bool $value,string $message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
foreach([JSON_ERROR_DEPTH,JSON_ERROR_SYNTAX,JSON_ERROR_UTF8] as $code){
    $error=new JsonException('PRIVATE form content, contact details and token=secret',$code);
    $log=$format->invoke(null,$error,'diagnostic-correlation');
    $assert(str_contains($log,'json_error_code='.$code),'JSON classification missing');
    $assert(str_contains($log,'source=api-failure-log.php:'),'Safe source location missing');
    $assert(str_contains($log,'correlation_id=diagnostic-correlation'),'Correlation missing');
    $assert(!str_contains($log,'PRIVATE')&&!str_contains($log,'secret')&&!str_contains($log,dirname(__DIR__)),'Sensitive exception message or directory logged');
}
$other=$format->invoke(null,new RuntimeException('PRIVATE credential=secret'),null);
$assert($other==='API failure RuntimeException correlation_id=unavailable','Non-JSON logging changed or exposes message');
try{json_decode('{invalid',true,32,JSON_THROW_ON_ERROR);}catch(JsonException $error){
    $assert(str_contains($format->invoke(null,$error,'actual-decode'),'json_error_code=4'),'Actual decoding failure not classified');
}
echo "API failure logging: $checks checks passed.\n";
