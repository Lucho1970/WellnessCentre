<?php
declare(strict_types=1);
// Loopback-only synthetic SQL/HTTP. Reuse the preceding authorization regressions.
require __DIR__.'/consent-notifications.php';
use Wellness\{Config};
use Wellness\Service\{LocationDiscoveryService,TravelEnquiryService,ConsentLock};

$checks=0;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/048_location_discovery.sql'));
$set(101,0,false);$set(101,1,true);$set(101,2,true);$set(104,1,false);$set(104,2,false);
$pdo->exec("UPDATE services SET published=1 WHERE id=1;
    UPDATE practitioner_services SET offers_mobile=1,mobile_radius_km=50 WHERE practitioner_id=2 AND service_id=1;
    INSERT IGNORE INTO practitioner_services(practitioner_id,service_id,offers_mobile,mobile_radius_km) VALUES(3,1,1,50);
    UPDATE locations SET address_line1='1 Synthetic Street',city='Toronto',province='ON',postal_code='M5V 1A1' WHERE id=1;
    INSERT INTO public_team_profiles(user_id,clinic_id,slug,section,public_name,public_title,published,show_booking_action)
      VALUES(2,1,'travel-consent-practitioner','practitioner','Practitioner','RMT',1,1),(3,1,'travel-consent-other','practitioner','Other practitioner','RMT',1,1);");
// Match the router's synthetic signing/cache key; provider results are cached locally, never real requests.
$travelConfig=new Config('test',false,'synthetic-key',[],'127.0.0.1',$port,$name,'root','','tenant','api','scope',300);
$providerCalls=0;
$discovery=new LocationDiscoveryService($database,$travelConfig,function(string $op,array $body)use(&$providerCalls):array{$providerCalls++;return $op==='geocode'?['latitude'=>43.65,'longitude'=>-79.38]:array_fill(0,count($body['origins']),60000);});
$matches=$discovery->search(1,['point'=>['latitude'=>43.7,'longitude'=>-79.4]],'synthetic-travel-ip')['matches'];
$tokens=[];foreach($matches as $match)$tokens[$match['practitioner_id']]=$match['token'];
$expect(isset($tokens[2],$tokens[3]),'Synthetic discovery proofs ready without external maps');
$travel=new TravelEnquiryService($database,$discovery);
$reference=static fn():string=>vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));
$make=function(int $client=104,int $practitioner=2,int $clinic=1)use($pdo,$reference):int{
    $q=$pdo->prepare('INSERT INTO travel_enquiries(clinic_id,client_id,practitioner_id,service_id,location_id,reference,request_hash,search_label) VALUES(?,?,?,?,?,?,?,?)');
    $ref=$reference();$q->execute([$clinic,$client,$practitioner,$clinic,$clinic,$ref,hash('sha256',$ref),'PRIVATE-AREA-'.$client]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)')->execute([$id,$client,$reference(),'PRIVATE-MESSAGE-'.$client]);return $id;
};
$visibleThreads=[];for($i=0;$i<52;$i++)$visibleThreads[]=$make();
$otherThread=$make(104,3);$hiddenThreads=[];for($i=0;$i<55;$i++)$hiddenThreads[]=$make(101);
$hiddenThread=end($hiddenThreads);$foreignThread=$make(105,5,2);$visibleThread=end($visibleThreads);
$pdo->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)')->execute([$visibleThread,2,$reference(),'PRIVATE-STAFF-REPLY']);
$messageIds=[];for($i=0;$i<102;$i++){$pdo->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)')->execute([$visibleThread,104,$reference(),'Page message '.$i]);$messageIds[]=(int)$pdo->lastInsertId();}
$pdo->exec('UPDATE customer_sessions SET revoked_at=NULL WHERE identity_id=1');
$customer=fn(string $path,string $method='GET',?array $body=null)=>$call('/api/v1/customer/'.$path,$customerToken,$method,$body,'audit-a.test',true);
$snapshot=function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT status,client_seen_id,practitioner_seen_id,(SELECT COUNT(*) FROM travel_enquiry_messages WHERE enquiry_id=e.id) messages FROM travel_enquiries e WHERE id=?');$q->execute([$id]);return $q->fetch();};
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-travel-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-travel-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start travel API');fclose($pipes[0]);
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Travel API ready');
    $expected=$visibleThreads;rsort($expected);
    foreach([[$practitionerToken,52],[$adminToken,53],[$otherToken,1]] as [$token,$count]){
        [$status,$countData,$cid,$raw]=$call('/api/v1/travel-enquiries/unread-count?client_id=101&practitioner_id=3&clinic_id=2&before=1',$token);
        $expect($status===200&&$countData['data']===['unread_count'=>$count]&&!str_contains($raw,'PRIVATE-'),'Count-only HTTP route applies authorization and ignores submitted scope/page IDs');
        $auditEvent=$event($cid)[0];$metadata=json_decode($auditEvent['metadata'],true);
        $expect($auditEvent['outcome']==='success'&&!str_contains(json_encode($metadata),'PRIVATE-'),'Count access is audited without patient content');
    }
    [$status,$countData]=$customer('travel-enquiries/unread-count');$expect($status===200&&array_keys($countData['data'])===['unread_count'],'Customer-session route supports count-only self-access');
    [$status,$countData,$cid]=$call('/api/v1/travel-enquiries/unread-count',null);$expect($status===401&&!isset($countData['data']['unread_count'])&&$event($cid)[0]['outcome']==='denied','Unauthenticated count request is denied and audited');
    $pdo->exec("UPDATE users SET status='inactive' WHERE id=2");
    try{[$status,$countData]=$call('/api/v1/travel-enquiries/unread-count',$practitionerToken);$expect(in_array($status,[401,403],true)&&!isset($countData['data']['unread_count']),'Persisted account deactivation prevents count disclosure');}
    finally{$pdo->exec("UPDATE users SET status='active' WHERE id=2");}
    [$status,$data,$cid,$raw]=$call('/api/v1/travel-enquiries',$practitionerToken);$data=$data['data'];
    $expect($status===200&&$ids($data['items'])===array_slice($expected,0,50)&&$data['unread_count']===52,'Own inbox filters hidden threads before unread count and page cap');
    $expect((int)$data['next_before']===array_slice($expected,0,50)[49]&&!str_contains($raw,'PRIVATE-AREA-101'),'Cursor contains the last permitted thread, no hidden visit area');
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$expect(!in_array(['type'=>'travel_enquiry','id'=>$hiddenThread],$refs,true),'List audit excludes hidden enquiry IDs');
    [$status,$page]=$call('/api/v1/travel-enquiries?before='.$data['next_before'],$practitionerToken);$expect($status===200&&$ids($page['data']['items'])===array_slice($expected,50)&&$page['data']['next_before']===null,'Next page returns remaining permitted threads');
    [$status,$data]=$call('/api/v1/travel-enquiries?client_id=101&practitioner_id=3&clinic_id=2',$practitionerToken);$expect($status===200&&$data['data']['unread_count']===52,'Delegation and submitted scope IDs never broaden the own inbox');
    [$status,$data]=$call('/api/v1/travel-enquiries',$adminToken);$expect($status===200&&$data['data']['unread_count']===53,'Restricted administrator retains permitted clinic-wide inbox');
    [$status,$data]=$call('/api/v1/travel-enquiries',$otherToken);$expect($status===200&&$ids($data['data']['items'])===[$otherThread],'Other practitioner sees only their own threads');
    foreach([$hiddenThread,$otherThread,$foreignThread,999999] as $id){
        $before=$id===999999?null:$snapshot($id);
        foreach([['GET','',null],['GET','?after=1',null],['POST','/reply',['reference'=>$reference(),'message'=>'Attempt']],['POST','/read',['message_id'=>999999]],['POST','/close',[]]] as [$method,$suffix,$body]){
            [$status,$data,$cid,$raw]=$call('/api/v1/travel-enquiries/'.$id.$suffix,$practitionerToken,$method,$body);
            $expect($status===404&&!str_contains($raw,'PRIVATE-')&&$data['error']['code']==='enquiry_not_found','Hidden/foreign/other/missing thread and message actions concealed');
            $expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied enquiry action audited without message contents');
        }
        if($before!==null)$expect($snapshot($id)===$before,'Denied replies, read receipts and close mutate no thread data');
    }
    [$status,$data,$cid]=$call('/api/v1/travel-enquiries/'.$visibleThread,$practitionerToken);$detail=$data['data'];
    $expect($status===200&&count($detail['messages'])===100&&$detail['next_after']!==null,'Permitted message page retains 100-message cap');
    $expect(!str_contains($event($cid)[0]['metadata'],'PRIVATE-')&&!str_contains($event($cid)[0]['metadata'],'Page message'),'Message bodies and visit labels never enter access audit metadata');
    [$status,$data]=$call('/api/v1/travel-enquiries/'.$visibleThread.'?after='.$detail['next_after'],$practitionerToken);$expect($status===200&&count($data['data']['messages'])===4&&$data['data']['next_after']===null,'Message cursor returns remaining permitted messages');
    $reply=['reference'=>$reference(),'message'=>'A permitted staff reply'];
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/reply',$practitionerToken,'POST',$reply);$expect($status===200,'Permitted staff reply commits');
    $before=$snapshot($visibleThread);[$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/reply',$practitionerToken,'POST',$reply);$expect($status===200&&$snapshot($visibleThread)===$before,'Reply replay is duplicate-safe');
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/reply',$practitionerToken,'POST',array_replace($reply,['message'=>'Changed']));$expect($status===409,'Same reply reference cannot authorize changed contents');
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/read',$practitionerToken,'POST',['message_id'=>end($messageIds)]);$expect($status===200&&$snapshot($visibleThread)['practitioner_seen_id']>0,'Permitted read acknowledgement advances only its thread');
    // Withdrawal since the list/message page was fetched is enforced before validation/replay.
    $set(104,2,true);$before=$snapshot($visibleThread);
    foreach([['GET','',null],['POST','/reply',$reply],['POST','/read',['message_id'=>0]],['POST','/close',[]]] as [$method,$suffix,$body]){[$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.$suffix,$practitionerToken,$method,$body);$expect($status===404&&$snapshot($visibleThread)===$before,'Stale list grants no thread/action/replay authority after withdrawal');}
    [$status,$data]=$call('/api/v1/travel-enquiries',$practitionerToken);$expect($status===200&&$data['data']['items']===[]&&$data['data']['unread_count']===0&&$data['data']['next_before']===null,'All-restricted inbox is empty without a whole-clinic block');
    [$status,$data]=$call('/api/v1/travel-enquiries/unread-count',$practitionerToken);$expect($status===200&&$data['data']===['unread_count'=>0],'Count endpoint filters after withdrawal without a conservative whole-clinic block');
    $set(104,2,false);
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread,$practitionerToken);$expect($status===200,'Restoration applies on the next thread request');
    $pdo->exec('UPDATE practitioners SET active=0 WHERE id=2');try{[$status,$data]=$call('/api/v1/travel-enquiries',$practitionerToken);$expect($status===200&&$data['data']['items']===[],'Inactive practitioner has no own inbox');[$status]=$call('/api/v1/travel-enquiries/'.$visibleThread,$practitionerToken);$expect($status===404,'Inactive profile cannot access a thread');}finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=2');}
    [$status]=$call('/api/v1/travel-enquiries',$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host switch cannot grant foreign clinic access');

    [$status,$data]=$customer('travel-enquiries/'.$hiddenThread);$expect($status===200&&$data['data']['messages'][0]['body']==='PRIVATE-MESSAGE-101','Patient retains their own restricted thread history');
    [$status]=$customer('travel-enquiries/'.$visibleThread);$expect($status===404,'Patient cannot read another client thread');
    [$status]=$customer('travel-enquiries/'.$hiddenThread.'/close','POST',[]);$expect($status===403,'Patient cannot acquire staff close authority');
    $before=$snapshot($hiddenThread);[$status,$data]=$customer('travel-enquiries/'.$hiddenThread.'/reply','POST',['reference'=>$reference(),'message'=>'Send to restricted practitioner']);
    $expect($status===409&&$data['error']['code']==='travel_recipient_unavailable'&&$snapshot($hiddenThread)===$before,'Patient cannot use a reply to override recipient withdrawal');
    [$status]=$customer('travel-enquiries/'.$hiddenThread.'/read','POST',['message_id'=>0]);$expect($status===200,'Patient may acknowledge own history despite staff restriction');
    $body=['reference'=>$reference(),'token'=>$tokens[2],'search_label'=>'Synthetic area','message'=>'Would you consider extra travel?'];
    $before=(int)$pdo->query('SELECT COUNT(*) FROM travel_enquiries')->fetchColumn();[$status]=$customer('travel-enquiries','POST',$body);$expect($status===409&&(int)$pdo->query('SELECT COUNT(*) FROM travel_enquiries')->fetchColumn()===$before,'Signed discovery proof is not a consent override');
    $body['token']=$tokens[3];[$status,$data]=$customer('travel-enquiries','POST',$body);$created=(int)$data['data']['id'];$expect($status===200&&$created>0,'Patient can contact another permitted practitioner');
    [$status,$data]=$customer('travel-enquiries','POST',$body);$expect($status===200&&(int)$data['data']['id']===$created,'Creation replay preserves one enquiry and initial message');
    $set(101,3,true);$before=$snapshot($created);[$status]=$customer('travel-enquiries','POST',$body);$expect($status===409&&$snapshot($created)===$before,'Creation replay rechecks recipient restriction');
    $set(101,3,false);$pdo->exec('UPDATE practitioners SET active=0 WHERE id=3');try{[$status]=$customer('travel-enquiries/'.$created.'/reply','POST',['reference'=>$reference(),'message'=>'Inactive recipient']);$expect($status===409,'Patient cannot send to an inactive practitioner');}finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=3');}
    $set(101,0,true);$set(101,1,false);
    [$status]=$call('/api/v1/travel-enquiries/'.$hiddenThread,$adminToken);$expect($status===404,'Global restriction overrides administrator/named restoration');
    [$status]=$customer('travel-enquiries/'.$hiddenThread);$expect($status===200,'Global withdrawal preserves patient self-access');
    $set(101,0,false);[$status]=$call('/api/v1/travel-enquiries/'.$hiddenThread,$adminToken);$expect($status===200,'Restored administrator may access the thread without granting restricted practitioner access');
    [$status]=$call('/api/v1/travel-enquiries/'.$hiddenThread.'/reply',$adminToken,'POST',['reference'=>$reference(),'message'=>'Clinic team reply to the patient']);$expect($status===200,'Permitted clinic staff can reply while assigned practitioner stays restricted');
    [$status]=$call('/api/v1/travel-enquiries/'.$hiddenThread,$practitionerToken);$expect($status===404,'Clinic reply does not restore blocked practitioner access');

    $pdo->exec("CREATE TRIGGER travel_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action IN ('travel_enquiry.reply','travel_enquiry.close','travel_enquiry.read','travel_enquiry.create') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic travel audit failure'; END IF; END");
    try{foreach([['reply',['reference'=>$reference(),'message'=>'Must roll back']],['close',[]],['read',['message_id'=>999999]]] as [$action,$body]){$before=$snapshot($visibleThread);[$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/'.$action,$practitionerToken,'POST',$body);$expect($status===500&&$snapshot($visibleThread)===$before,'Audit failure rolls back messages, closure and read receipts');}}
    finally{$pdo->exec('DROP TRIGGER travel_audit_failure');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{[$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/close',$practitionerToken,'POST',[]);$expect($status===503&&$snapshot($visibleThread)['status']==='open','Consent lock prevents mutations racing withdrawal');}finally{$lock->release();}
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/close',$practitionerToken,'POST',[]);$expect($status===200&&$snapshot($visibleThread)['status']==='closed','Permitted staff can close after lock release');
    [$status]=$call('/api/v1/travel-enquiries/'.$visibleThread.'/reply',$practitionerToken,'POST',['reference'=>$reference(),'message'=>'New closed-thread message']);$expect($status===409,'New messages remain prohibited after close');
    $denies(fn()=>$travel->list($actor(1,['accountant'])),403);
    $denies(fn()=>$travel->detail($actor(2,['super_admin','practitioner'],['schedule_for_other_practitioners']),$hiddenThread),404);
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_travel_directives');
    try{foreach(['/api/v1/travel-enquiries','/api/v1/travel-enquiries/unread-count','/api/v1/travel-enquiries/'.$hiddenThread] as $path){[$status,,$cid,$raw]=$call($path,$adminToken);$expect($status===500&&!str_contains($raw,'PRIVATE-'),'Missing policy storage blocks staff thread disclosures');}$before=$snapshot($hiddenThread);[$status]=$call('/api/v1/travel-enquiries/'.$hiddenThread.'/close',$adminToken,'POST',[]);$expect($status===500&&$snapshot($hiddenThread)===$before,'Missing policy storage blocks thread mutations');}
    finally{$pdo->exec('RENAME TABLE retained_travel_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Filtered travel enquiry/message SQL/HTTP authorization: $checks checks passed.\n";
