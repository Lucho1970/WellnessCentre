<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\ClientFormDefinition;
use Wellness\Http\ApiException;
$checks=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Form definition assertion failed.');$checks++;};
$denies=function(callable $action)use($assert){try{$action();}catch(ApiException $e){$assert($e->status===422);return;}throw new RuntimeException('Expected invalid form.');};
$q=['id'=>'health','label'=>'Synthetic question','label_fr'=>'Question fictive','type'=>'text','required'=>true];
$definition=['instructions'=>'Synthetic only','questions'=>[$q,['id'=>'yes','label'=>'Yes/no','type'=>'yes_no','required'=>true],['id'=>'agree','label'=>'Synthetic consent','type'=>'consent','required'=>true]]];
$validated=ClientFormDefinition::definition($definition,'consent');
$assert(ClientFormDefinition::answers($validated,['health'=>' Test ','yes'=>false,'agree'=>true])===['agree'=>true,'health'=>'Test','yes'=>false]);
foreach([null,[],['questions'=>[]],['questions'=>array_fill(0,31,$q)],['questions'=>[$q,$q]],['questions'=>[array_replace($q,['id'=>'<script>'])]],['questions'=>[array_replace($q,['type'=>'html'])]],['questions'=>[array_replace($q,['required'=>1])]],['questions'=>[array_replace($q,['label'=>''])]],['questions'=>[array_replace($q,['label'=>str_repeat('a',501)])]],['questions'=>[$q],'unknown'=>'value']] as $invalid)$denies(fn()=>ClientFormDefinition::definition($invalid,'intake'));
$denies(fn()=>ClientFormDefinition::definition(['questions'=>[$q]],'consent'));
$denies(fn()=>ClientFormDefinition::definition(['questions'=>[array_replace($q,['type'=>'consent','required'=>false])]],'consent'));
foreach([[],['health'=>'ok','yes'=>true,'agree'=>false],['health'=>'ok','yes'=>'false','agree'=>true],['health'=>str_repeat('a',2001),'yes'=>false,'agree'=>true],['health'=>'ok','yes'=>false,'agree'=>true,'other'=>'secret'],['health'=>['bad'],'yes'=>false,'agree'=>true]] as $invalid)$denies(fn()=>ClientFormDefinition::answers($validated,$invalid));
$optional=ClientFormDefinition::definition(['questions'=>[array_replace($q,['required'=>false])]],'intake');$assert(ClientFormDefinition::answers($optional,[])===[]);
$typed=ClientFormDefinition::definition(['questions'=>[
    array_replace($q,['id'=>'birth','type'=>'date','no_future'=>true]),
    array_replace($q,['id'=>'visit','type'=>'date','required'=>false]),
    array_replace($q,['id'=>'phone','type'=>'phone']),
    array_replace($q,['id'=>'email','type'=>'email'])
]],'intake');
$valid=['birth'=>'2000-02-29','phone'=>'+14165551234','email'=>' test@example.test '];
$assert(ClientFormDefinition::answers($typed,$valid)===['birth'=>'2000-02-29','email'=>'test@example.test','phone'=>'+14165551234']);
$assert(ClientFormDefinition::answers($typed,$valid+['visit'=>'2099-12-31'])['visit']==='2099-12-31');
$assert(!array_key_exists('visit',ClientFormDefinition::answers($typed,$valid+['visit'=>''])));
foreach(['2001-02-29','0000-01-01','2000-13-01','2000-04-31','03/04/2000','2000-2-01','2000-02-29T00:00:00Z','9999-12-31',''] as $date)$denies(fn()=>ClientFormDefinition::answers($typed,array_replace($valid,['birth'=>$date])));
foreach(['+1416555','4165551234','+19995551234','+14165551234 ext 9',[],false,''] as $phone)$denies(fn()=>ClientFormDefinition::answers($typed,array_replace($valid,['phone'=>$phone])));
foreach(['bad@','bad@@example.test','bad name@example.test',str_repeat('x',255).'@example.test',[],false,''] as $email)$denies(fn()=>ClientFormDefinition::answers($typed,array_replace($valid,['email'=>$email])));
$assert(ClientFormDefinition::answers($typed,array_replace($valid,['phone'=>'+442079460018']))['phone']==='+442079460018');
$denies(fn()=>ClientFormDefinition::definition(['questions'=>[array_replace($q,['no_future'=>true])]],'intake'));
$denies(fn()=>ClientFormDefinition::definition(['questions'=>[array_replace($q,['type'=>'date','no_future'=>'true'])]],'intake'));
$optionalTypes=ClientFormDefinition::definition(['questions'=>array_map(fn($type)=>array_replace($q,['id'=>$type,'type'=>$type,'required'=>false]),['date','phone','email'])],'intake');
$assert(ClientFormDefinition::answers($optionalTypes,['date'=>'','phone'=>'','email'=>''])===[]);
echo "Client form definition: $checks checks passed.\n";
