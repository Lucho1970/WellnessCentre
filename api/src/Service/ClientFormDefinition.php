<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Http\ApiException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

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
            if(!is_array($q)||array_diff(array_keys($q),['id','label','label_fr','type','required','no_future']))self::invalid();
            $id=$q['id']??null;$kind=$q['type']??null;
            if(!is_string($id)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/D',$id)||isset($ids[$id])||!in_array($kind,['text','yes_no','consent','date','phone','email'],true)||!is_bool($q['required']??null))self::invalid();
            if(array_key_exists('no_future',$q)&&($kind!=='date'||!is_bool($q['no_future'])))self::invalid();
            if($kind==='consent'&&!$q['required'])self::invalid();
            $ids[$id]=true;$consent=$consent||$kind==='consent';
            $result['questions'][]=['id'=>$id,'label'=>self::text($q['label']??null,500,true),'label_fr'=>self::text($q['label_fr']??'',500),'type'=>$kind,'required'=>$q['required']];
            if($kind==='date')$result['questions'][array_key_last($result['questions'])]['no_future']=$q['no_future']??false;
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
            if(in_array($q['type'],['date','phone','email'],true)&&$value===''){if($q['required'])self::invalid();continue;}
            if($q['type']==='text')$value=self::text($value,2000,$q['required']);
            elseif($q['type']==='date'){
                if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))self::invalid();
                $today=(new \DateTimeImmutable('now',new \DateTimeZone('America/Toronto')))->format('Y-m-d');
                if(($q['no_future']??false)&&$value>$today)self::invalid();
            }
            elseif($q['type']==='email'){
                $value=self::text($value,254,true);if(filter_var($value,FILTER_VALIDATE_EMAIL)===false)self::invalid();
            }
            elseif($q['type']==='phone'){
                $value=self::text($value,40,true);
                if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$value))self::invalid();
                $util=PhoneNumberUtil::getInstance();
                try{$number=$util->parse($value,null);}catch(NumberParseException){self::invalid();}
                if(!$util->isValidNumber($number)||$number->hasExtension())self::invalid();
                $value=$util->format($number,PhoneNumberFormat::E164);
            }
            elseif(!is_bool($value)||($q['type']==='consent'&&$value!==true))self::invalid();
            $result[$q['id']]=$value;
        }
        ksort($result);return $result;
    }
}
