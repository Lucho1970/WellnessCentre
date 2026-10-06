<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Http\ApiException;

final class ClientFormDefinition
{
    private static function invalid(): never { throw new ApiException(422,'invalid_form','Check the form questions and required answers.'); }
    private static function text(mixed $value,int $limit,bool $required=false): string {
        if(!is_string($value)||strlen($value)>$limit||($required&&trim($value)===''))self::invalid();
        return trim($value);
    }
    public static function definition(mixed $value,string $type): array {
        if(!is_array($value)||array_diff(array_keys($value),['instructions','instructions_fr','questions']))self::invalid();
        $questions=$value['questions']??null;
        if(!is_array($questions)||!array_is_list($questions)||count($questions)<1||count($questions)>30)self::invalid();
        $result=['instructions'=>self::text($value['instructions']??'',5000),'instructions_fr'=>self::text($value['instructions_fr']??'',5000),'questions'=>[]];$ids=[];$consent=false;
        foreach($questions as $q){
            if(!is_array($q)||array_diff(array_keys($q),['id','label','label_fr','type','required']))self::invalid();
            $id=$q['id']??null;$kind=$q['type']??null;
            if(!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D',$id)||isset($ids[$id])||!in_array($kind,['text','yes_no','consent'],true)||!is_bool($q['required']??null))self::invalid();
            if($kind==='consent'&&!$q['required'])self::invalid();
            $ids[$id]=true;$consent=$consent||$kind==='consent';
            $result['questions'][]=['id'=>$id,'label'=>self::text($q['label']??null,500,true),'label_fr'=>self::text($q['label_fr']??'',500),'type'=>$kind,'required'=>$q['required']];
        }
        if($type==='consent'&&!$consent)self::invalid();
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>55000)self::invalid();
        return $result;
    }
    public static function answers(array $definition,mixed $input): array {
        if(!is_array($input)||array_diff(array_keys($input),array_column($definition['questions'],'id')))self::invalid();
        $result=[];
        foreach($definition['questions'] as $q){
            $value=$input[$q['id']]??null;
            if($value===null){if($q['required'])self::invalid();continue;}
            if($q['type']==='text')$value=self::text($value,2000,$q['required']);
            elseif(!is_bool($value)||($q['type']==='consent'&&$value!==true))self::invalid();
            $result[$q['id']]=$value;
        }
        ksort($result);return $result;
    }
}
