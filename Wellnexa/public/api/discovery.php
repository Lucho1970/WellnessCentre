<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD']??'';
if(array_diff(array_keys($_GET),['action'])||!in_array($action,['options','search'],true)||$method!==($action==='options'?'GET':'POST')){http_response_code(404);echo '{"error":{"message":"Location search route unavailable."}}';exit;}
$raw=$action==='search'?(file_get_contents('php://input',false,null,0,8193)?:'{}'):'';
if(strlen($raw)>8192){http_response_code(413);echo '{"error":{"message":"Location request is too large."}}';exit;}
try{
    if($action==='search'){
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($input)||array_diff(array_keys($input),['point','area','only_within']))throw new RuntimeException('Invalid search.');
    }
    // Fixed public upstream. No authentication, cookie, caller-selected host or forwarded IP.
    $curl=curl_init('https://wellnexa.copihue.ca/api/v1/public/discovery/'.$action);$response='';
    try{
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>2097152)return 0;$response.=$chunk;return strlen($chunk);}]);
        if($action==='search')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw]);
        if(curl_exec($curl)===false)throw new RuntimeException('Search unavailable.');$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    }finally{curl_close($curl);}
    $data=json_decode($response,true,32,JSON_THROW_ON_ERROR);
    if($status!==200||!is_array($data['data']??null)){
        http_response_code(in_array($status,[400,413,422,429],true)?$status:503);
        echo json_encode(['error'=>['message'=>$status===429?'Too many location searches. Please try again later.':($status===422?'Choose a more specific Canadian city, postal code or address.':'Location search is temporarily unavailable. Please retry or contact the clinic.')]]);exit;
    }
    $source=$data['data'];
    if($action==='options')$result=array_intersect_key($source,array_flip(['extra_km','cap_km','revision','map_browser_key']));
    else{
        $result=array_intersect_key($source,array_flip(['point','label','settings','approximate_search']));
        $result['matches']=array_map(static fn($row)=>array_intersect_key($row,array_flip(['practitioner_id','service_id','location_id','practitioner_slug','service_slug','status','token','starting_price_cents'])),$source['matches']??[]);
    }
    echo json_encode(['data'=>$result],JSON_THROW_ON_ERROR);
}catch(Throwable){http_response_code(503);echo '{"error":{"message":"Location search is temporarily unavailable. Please retry or contact the clinic."}}';}
