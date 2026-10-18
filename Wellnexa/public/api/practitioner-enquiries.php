<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){header('Allow: POST');http_response_code(405);echo '{"error":{"message":"Only enquiry submissions are supported."}}';exit;}
if($_GET!==[]){http_response_code(400);echo '{"error":{"message":"Unexpected enquiry parameters."}}';exit;}
$raw=file_get_contents('php://input',false,null,0,16385)?:'';
if(strlen($raw)>16384){http_response_code(413);echo '{"error":{"message":"The enquiry is too large."}}';exit;}
try{$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new RuntimeException('Invalid body');}
catch(Throwable){http_response_code(400);echo '{"error":{"message":"Use a valid enquiry form."}}';exit;}
try{
    $curl=curl_init('https://wellnexa.copihue.ca/api/v1/public/practitioner-enquiries');
    if($curl===false)throw new RuntimeException('Unavailable');
    $response='';
    try{
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>16384)return 0;$response.=$chunk;return strlen($chunk);}]);
        if(curl_exec($curl)===false)throw new RuntimeException('Unavailable');
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);
        if($status===200&&($decoded['data']['received']??null)===true&&is_string($decoded['data']['reference']??null)){
            echo json_encode(['data'=>['received'=>true,'reference'=>$decoded['data']['reference']]],JSON_THROW_ON_ERROR);exit;
        }
        if(in_array($status,[400,413,422,429],true)){http_response_code($status);echo json_encode(['error'=>['message'=>$status===429?'Too many enquiries. Please try again later.':'Check your enquiry details and agreement to be contacted.']]);exit;}
        if($status===503&&($decoded['error']['code']??null)==='enquiries_closed'){http_response_code(503);echo '{"error":{"code":"enquiries_closed","message":"Practitioner enquiries are temporarily closed. Please contact the clinic."}}';exit;}
        throw new RuntimeException('Unconfirmed');
    }finally{curl_close($curl);}
}catch(Throwable){http_response_code(503);echo '{"error":{"message":"We could not confirm receipt. Please retry here using the same enquiry."}}';}
