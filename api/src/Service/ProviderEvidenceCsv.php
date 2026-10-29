<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Http\ApiException;

/** Application-owned normalized evidence format. Not an undocumented native provider export. */
final class ProviderEvidenceCsv
{
    public static function parse(string $csv): array
    {
        if(strlen($csv)>50000||!mb_check_encoding($csv,'UTF-8')||str_contains($csv,"\0"))self::invalid();
        $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);$stream=fopen('php://temp','r+');fwrite($stream,$csv);rewind($stream);
        try{
            $header=fgetcsv($stream,0,',','"','');
            if($header!==['type','reference','sender_did','destination','occurred_at','currency','amount'])self::invalid();
            $rows=[];$seen=[];
            while(($fields=fgetcsv($stream,0,',','"',''))!==false){
                if($fields===[null])continue;
                if(count($fields)!==7||count($rows)>=500)self::invalid();
                [$type,$reference,$did,$number,$at,$currency,$amount]=array_map('trim',$fields);
                if(!in_array($type,['charge','stop'],true)||!preg_match('/^[A-Za-z0-9:_-]{1,120}$/D',$reference)||!CanadianSmsNumber::isAllowed('+1'.$did)||!CanadianSmsNumber::isAllowed($number))self::invalid();
                $instant=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$at,new \DateTimeZone('UTC'));
                if(!$instant||$instant->format('Y-m-d H:i:s')!==$at||$instant->getTimestamp()>time()+300)self::invalid();
                if($type==='stop'){if($currency!==''||$amount!=='')self::invalid();$micros=null;}
                else{if(!in_array($currency,['CAD','USD'],true))self::invalid();$micros=self::decimal($amount,1000000000000);}
                $key=hash('sha256',json_encode(['voipms',$type,$did,$reference],JSON_THROW_ON_ERROR));if(isset($seen[$key]))self::invalid();$seen[$key]=true;
                $rows[]=['line'=>count($rows)+2,'type'=>$type,'reference'=>$reference,'sender_did'=>$did,'number_hash'=>hash('sha256',$number),'occurred_at'=>$at,'currency'=>$currency,'amount_micros'=>$micros,'source_key'=>$key];
            }
            if(!$rows)self::invalid();return $rows;
        }finally{fclose($stream);}
    }
    public static function decimal(string $value,int $max): int
    {
        if(!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,6}))?$/D',$value,$m))self::invalid();
        $number=(int)$m[1]*1000000+(int)str_pad($m[2]??'',6,'0');if($number>$max)self::invalid();return $number;
    }
    public static function cents(int $amountMicros,int $rateMicros): int
    {
        if($amountMicros<0||$amountMicros>1000000000000||$rateMicros<1||$rateMicros>100000000)self::invalid();
        // Split before multiplication to avoid overflowing at the supported amount/rate limits.
        $whole=intdiv($amountMicros,1000000)*$rateMicros;
        $remainder=($whole%10000)*1000000+($amountMicros%1000000)*$rateMicros;
        $cents=intdiv($whole,10000)+intdiv($remainder+9999999999,10000000000);
        if($cents>100000000)self::invalid();return $cents;
    }
    private static function invalid(): never {throw new ApiException(422,'provider_evidence_invalid','Check the normalized provider evidence format and exact decimal amounts.');}
}
