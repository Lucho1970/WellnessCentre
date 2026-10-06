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
echo "Client form definition: $checks checks passed.\n";
