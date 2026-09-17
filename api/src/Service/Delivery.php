<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Http\ApiException;

final class Delivery
{
    public static function mode(array $input): string
    {
        $mode=$input['delivery_mode']??'clinic';
        if(!in_array($mode,['clinic','mobile'],true))throw new ApiException(422,'invalid_delivery','Choose clinic or mobile delivery.');
        return $mode;
    }

    public static function destination(array $body): ?array
    {
        if(self::mode($body)==='clinic')return null;
        $value=$body['destination']??null;
        if(!is_array($value))throw new ApiException(422,'invalid_destination','A client visit address is required.');
        $result=[];
        foreach(['address_line1'=>190,'address_line2'=>190,'city'=>100,'province'=>80,'postal_code'=>20,'country'=>80,'instructions'=>500] as $key=>$max){
            $text=$value[$key]??'';
            if(!is_string($text)||strlen($text)>$max)throw new ApiException(422,'invalid_destination',"Invalid {$key}.");
            $text=trim($text);
            if(!in_array($key,['address_line2','instructions'],true)&&$text==='')throw new ApiException(422,'invalid_destination',"{$key} is required.");
            $result[$key]=$text;
        }
        return $result;
    }

    public static function terms(array $rule,string $mode): array
    {
        if(!(int)($rule[$mode==='mobile'?'offers_mobile':'offers_clinic']??0))throw new ApiException(422,'delivery_unavailable','This practitioner does not offer the selected delivery option.');
        $base=(int)($rule['base_price_cents']??0);
        return ['requires_room'=>$mode==='clinic'&&(bool)(int)$rule['requires_room'],
            'travel'=>$mode==='mobile'?(int)$rule['travel_buffer_minutes']:0,
            'base'=>$base,'fee'=>$mode==='mobile'?(int)$rule['mobile_fee_cents']:0];
    }
}
