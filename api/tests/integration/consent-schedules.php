<?php
declare(strict_types=1);
// Local synthetic SQL/HTTP only; retains all previous consent/access authorization regressions.
require __DIR__.'/consent-directives.php';

use Wellness\Service\{AdminService,DashboardService,PractitionerVisitService,PractitionerTravelService};

$checks=0;
$pdo->exec("UPDATE appointments SET status='canceled_by_clinic'; UPDATE notification_events SET status='canceled';
    INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='practitioner';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','other');
    INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(3,1);
    INSERT INTO locations(id,clinic_id,name,timezone) VALUES(2,2,'Foreign location','America/Toronto');
    INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(2,2,'foreign','Foreign service',100);");
$utc=new DateTimeZone('UTC');$zone=new DateTimeZone('America/Toronto');
$now=new DateTimeImmutable('now',$utc);$local=$now->setTimezone($zone);$day=$local->setTime(0,0)->setTimezone($utc);
$insert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by,delivery_mode,destination_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?,'admin',1,?,?)");
$make=function(int $client,int $practitioner,DateTimeImmutable $start,string $status='confirmed',string $mode='mobile',int $clinic=1)use($insert,$pdo):int{
    $from=$start->format('Y-m-d H:i:s');$to=$start->modify('+10 minutes')->format('Y-m-d H:i:s');
    $insert->execute([$clinic,$clinic,$client,$practitioner,$clinic,$from,$to,$from,$to,$status,$mode,json_encode(['address_line1'=>'Private synthetic destination','city'=>'Toronto','province'=>'ON','postal_code'=>'M5V 1A1'])]);
    return (int)$pdo->lastInsertId();
};
// Hidden rows exceed both the day and summary caps: filtering must happen before LIMIT.
$pdo->beginTransaction();
for($i=0;$i<55;$i++)$make(101,2,$day->modify('+20 minutes'));
for($i=0;$i<501;$i++)$make(101,2,$day->modify('-1 day +20 minutes'),'completed');
$hidden=$make(101,2,$now->modify('+10 minutes'));
$pastStart=$now->modify('-1 hour');$past=$make(104,2,$pastStart,'completed');
$next=$make(104,2,$now->modify('+20 minutes'));
$other=$make(102,3,$day->modify('+10 minutes'),'completed','clinic');
$foreign=$make(105,5,$now->modify('+6 minutes'),'confirmed','mobile',2);
$pdo->commit();
foreach([[$past,'arrived'],[$past,'left_residence'],[$hidden,'arrived']] as [$id,$code])$pdo->prepare("INSERT INTO appointment_visit_events(clinic_id,appointment_id,practitioner_user_id,event_code,event_action,occurred_at) VALUES(1,?,2,?,'record',UTC_TIMESTAMP())")->execute([$id,$code]);
$offStart=$day->format('Y-m-d H:i:s');$offEnd=$day->modify('+2 days')->format('Y-m-d H:i:s');
$off=$pdo->prepare("INSERT INTO time_off(practitioner_id,location_id,starts_at,ends_at,reason_type,created_by) VALUES(2,1,?,?,'vacation',1)");
$off->execute([$offStart,$offEnd]);$timeOff=(int)$pdo->lastInsertId();$off->execute([$offStart,$offEnd]);
$policy=new \Wellness\Service\ClientConsentService($database,$audit);
$patientActor=$actor(101,[],[],'client');
$version=(int)$pdo->query("SELECT version FROM client_consent_directives WHERE client_id=101 AND scope='staff_access' AND target_staff_id=2")->fetchColumn();
$policy->save($patientActor,101,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>true,'version'=>$version],'schedule-restrict-2');
$policy->save($patientActor,101,['scope'=>'staff_access','target_staff_id'=>1,'restricted'=>true,'version'=>0],'schedule-restrict-1');
$notification=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,sent_at) VALUES(?,?,?,'synthetic@example.test','booking_confirmation',?,?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),CASE WHEN ? IN ('sent','delivered') THEN UTC_TIMESTAMP() ELSE NULL END)");
foreach([
    [1,$hidden,101,'email','failed'],[1,$next,104,'email','queued'],
    [1,$hidden,2,'sms','needs_review'],[1,$next,2,'sms','needs_review'],
    [1,$hidden,101,'email','sent'],[1,$next,104,'email','delivered'],
    [1,null,101,'sms','queued'],[1,null,104,'sms','queued'],
    [1,null,2,'email','needs_review'], // Cannot establish patient scope.
    [1,$foreign,105,'email','needs_review'], // Mismatched appointment clinic.
    [2,$foreign,105,'email','needs_review'],
] as $row)$notification->execute([...$row,$row[4]]);

// Restart the existing synthetic router, with the same in-memory signing material.
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-schedules-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-schedules-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start schedule test API');fclose($pipes[0]);
$otherToken=$staffToken('other','Wellness.Practitioner');
$ids=static fn(array $rows):array=>array_map('intval',array_column($rows,'id'));
$visibleToday=[];if($pastStart->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'))$visibleToday[]=$past;
$impactIds=$visibleToday;
if($now->modify('+20 minutes')->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'))$visibleToday[]=$next;
$summaryVisible=1+(int)($now->modify('+20 minutes')->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'));
$impactIds[]=$next;
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Synthetic schedule API ready');
    [$status,$data,$cid,$raw]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken);
    $expect($status===200,'Practitioner dashboard remains available under named restrictions');
    $values=$data['data']['values'];
    $expect($values['my_appointments_today']===count($visibleToday)&&$values['my_onsite_today']===count($visibleToday),'Counts exclude hidden records before aggregation');
    $expect((int)$values['my_next_appointment']['id']===$next,'Next appointment skips the earlier restricted appointment');
    $expect($data['data']['time_off_follow_ups']['count']===1&&$ids($data['data']['time_off_follow_ups']['appointments'])===[$next],'Overlapping time-off follow-ups are filtered and deduplicated');
    $expect(!str_contains($raw,'Synthetic Appointment')&&!str_contains($raw,'Private synthetic destination'),'Dashboard has no hidden name/destination');
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];
    $expect(in_array(['type'=>'appointment','id'=>$next],$refs,true)&&!in_array(['type'=>'appointment','id'=>$hidden],$refs,true),'Access audit contains disclosed appointments only');
    [$status,$data]=$call('/api/v1/practitioner/today',$practitionerToken);
    $expect($status===200&&$ids($data['data']['appointments'])===$visibleToday,'Today skips over hidden rows before day cap and event hydration');
    if(in_array($past,$visibleToday,true))$expect(isset($data['data']['appointments'][0]['events']['arrived']),'Visible visit steps are still available');
    [$status,$data]=$call('/api/v1/practitioner/visit-summary',$practitionerToken);
    $counts=$data['data']['counts'];
    $expect($status===200&&$counts['scheduled_visits']===$summaryVisible&&$counts['completed']===1,'Seven-day visit counts exclude more than 500 restricted visits');
    $expect($counts['visits_with_steps']===1&&$counts['onsite_arrivals']===1&&$counts['onsite_departures']===1,'Milestone aggregates use visible appointments only');
    [$status,$data]=$call('/api/v1/practitioner/next-onsite',$practitionerToken);$expect($status===200&&(int)$data['data']['id']===$next,'Next On-Site selection skips restricted destination');
    [$status,$data]=$call('/api/v1/admin/schedule-exceptions',$practitionerToken);
    $expect($status===200&&count($data['data'])===2&&array_map('intval',array_column($data['data'],'affected_appointment_count'))===[count($impactIds),count($impactIds)],'Time-off schedule counts are restricted to visible appointments');
    [$status,$data]=$call('/api/v1/admin/time-off/'.$timeOff.'/impact',$practitionerToken);
    $expect($status===200&&$ids($data['data']['appointments'])===$impactIds,'Saved time-off impact excludes hidden records before limit');
    $preview=['practitioner_id'=>2,'location_id'=>1,'starts_at'=>$day->format(DATE_ATOM),'ends_at'=>$day->modify('+2 days')->format(DATE_ATOM)];
    [$status,$data]=$call('/api/v1/admin/time-off/impact',$practitionerToken,'POST',$preview);
    $expect($status===200&&$ids($data['data']['appointments'])===$impactIds,'Time-off preview is a filtered read');
    [$status]=$call('/api/v1/admin/time-off',$practitionerToken,'POST',$preview+['reason_type'=>'vacation']);$expect($status===403,'Bulk time-off writes retain fail-closed authorization');
    foreach(['/api/v1/admin/availability-rules','/api/v1/practitioner/availability-context'] as $path){[$status]=$call($path,$practitionerToken);$expect($status===200,'Patient-free schedule setup is available');}
    foreach(['/api/v1/practitioner/today/'.$hidden.'/outcome','/api/v1/practitioner/today/'.$hidden.'/milestone','/api/v1/practitioner/next-onsite/'.$hidden.'/travel-estimate'] as $path){[$status]=$call($path,$practitionerToken,'POST',[]);$expect($status===403,'Hidden appointment action denied before validation or transport');}
    [$status]=$call('/api/v1/practitioner/appointments/'.$hidden.'/visit-history',$practitionerToken);$expect($status===403,'Hidden visit history remains denied');
    $visits=new PractitionerVisitService($database,$audit);
    $denies(fn()=>$visits->history($practitioner,$hidden,'hidden-direct-history'),404);
    $denies(fn()=>$visits->outcome($practitioner,$hidden,['version'=>1,'outcome'=>'completed'],'hidden-direct-outcome'),404);
    $denies(fn()=>$visits->today($operator,'operator-day'),403);
    $admin=new AdminService($database,$audit);
    $denies(fn()=>$admin->scheduleExceptions($actor(101,['super_admin'],[],'client')),403);
    $denies(fn()=>$admin->timeOffImpact($actor(3,['practitioner']),$timeOff),404);
    $routeConfig=new \Wellness\Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','tenant','api','scope',300,googleMapsApiKey:'synthetic-no-network');
    $transports=0;
    $travel=new PractitionerTravelService($database,$routeConfig,$audit,function()use(&$transports){$transports++;return ['routes'=>[['duration'=>'60s','distanceMeters'=>1000]]];});
    $denies(fn()=>$travel->estimate($practitioner,$hidden,['source'=>'clinic'],'hidden-travel'),404);
    $expect($transports===0,'Restricted destinations never reach a map provider');


    [$status,$data]=$call('/api/v1/dashboard?workspace=admin',$adminToken);$expect($status===200,'Restricted administrator can still see filtered dashboard');
    $metric=$data['data']['values']['notification_delivery_summary'];
    foreach(['today','last7'] as $period){$expect($metric[$period]['email']===['sent'=>1,'queued'=>($period==='last7'||$now->modify('-1 hour')->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'))?1:0,'failed'=>0,'needs_review'=>0],'Email activity is filtered for '.$period);$expect($metric[$period]['sms']===['sent'=>0,'queued'=>($period==='last7'||$now->modify('-1 hour')->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'))?1:0,'failed'=>0,'needs_review'=>($period==='last7'||$now->modify('-1 hour')->setTimezone($zone)->format('Y-m-d')===$local->format('Y-m-d'))?1:0],'SMS activity resolves staff notices via appointment and standalone client recipient');}
    $expect($metric['needs_review_total']===1&&$metric['health']['overdue_count']===2,'Unresolved and overdue totals use the same patient boundary');
    [$status,$data]=$call('/api/v1/dashboard?workspace=practitioner',$otherToken);
    $expect($status===200&&$data['data']['values']['my_appointments_today']===1,'Another practitioner retains their own schedule');
    [$status]=$call('/api/v1/practitioner/today',$adminToken);$expect($status===403,'Filtering never grants practitioner role');
    [$status]=$call('/api/v1/dashboard?workspace=admin',$practitionerToken);$expect($status===403,'Filtering never grants admin workspace');
    [$status]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken,'GET',null,'audit-b.test');$expect($status===403,'Host switch cannot change clinic authority');
    $delegated=$actor(2,['super_admin','practitioner'],['schedule_for_other_practitioners']);
    $dashboard=new DashboardService($database,$audit);
    $definition=(new ReflectionMethod(DashboardService::class,'builtIns'))->invoke($dashboard)['my_appointments_today'];
    $definition['id']='synthetic_filtered_metric';$definition['parameters']=[];
    // Install a stored widget definition: publication is outside this read-authorization test.
    $pdo->prepare('INSERT INTO dashboard_widgets(clinic_id,widget_key,active_version,created_by,updated_by) VALUES(1,?,1,1,1)')->execute([$definition['id']]);
    $widget=(int)$pdo->lastInsertId();$json=json_encode($definition,JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO dashboard_widget_versions(widget_id,version_number,definition_json,definition_hash,validation_result,created_by) VALUES(?,1,?,?, '{}',1)")->execute([$widget,$json,hash('sha256',$json)]);
    $value=$dashboard->summary($delegated,'practitioner')['values'];
    $expect($value['synthetic_filtered_metric']===2,'Custom widgets use the same consent filter as built-ins');
    $expect($value['my_appointments_today']===count($visibleToday),'Combined roles and scheduling delegation do not widen own dashboard or override consent');

    $pdo->exec("INSERT INTO user_permissions(user_id,permission_id,assigned_by) SELECT 2,id,1 FROM permissions WHERE code='schedule_for_other_practitioners'");
    [$status,$data]=$call('/api/v1/practitioner/today?practitioner_id=3&clinic_id=2',$practitionerToken);
    $expect($status===200&&$ids($data['data']['appointments'])===$visibleToday,'Delegation and submitted scope IDs cannot broaden own schedule');
    $pdo->exec('UPDATE practitioners SET active=0 WHERE id=2');
    try{
        [$status,$data]=$call('/api/v1/practitioner/today',$practitionerToken);$expect($status===200&&$data['data']['appointments']===[],'Inactive profile discloses no day cards');
        [$status,$data]=$call('/api/v1/practitioner/next-onsite',$practitionerToken);$expect($status===200&&$data['data']===null,'Inactive profile discloses no next visit');
        [$status,$data]=$call('/api/v1/admin/schedule-exceptions',$practitionerToken);$expect($status===200&&$data['data']===[],'Inactive profile has no own schedule impact counts');
        [$status]=$call('/api/v1/admin/time-off/'.$timeOff.'/impact',$practitionerToken);$expect($status===404,'Inactive profile cannot retrieve patient impact');
    }finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=2');}

    // Global withdrawal must apply to every staff member, while patient reads stay available.
    $global=(int)$pdo->query("SELECT version FROM client_consent_directives WHERE client_id=101 AND scope='staff_access' AND target_staff_id=0")->fetchColumn();
    $policy->save($patientActor,101,['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>true,'version'=>$global],'schedule-global');
    $make(101,3,$day->modify('+15 minutes'),'completed','clinic');
    [$status,$data]=$call('/api/v1/practitioner/today',$otherToken);$expect($status===200&&$ids($data['data']['appointments'])===[$other],'Global restriction does not stop unrelated practitioner work');
    [$status]=$call('/api/v1/customer/profile',$customerToken,'GET',null,'audit-a.test',true);$expect($status===200,'Patient self-access survives globally filtered schedules');
    // Restore named restriction while global is active: no grant through overlapping directives.
    $policy->save($patientActor,101,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>false,'version'=>$version+1],'schedule-named-restore');
    [$status,$data]=$call('/api/v1/practitioner/next-onsite',$practitionerToken);$expect($status===200&&(int)$data['data']['id']===$next,'Named restoration cannot override global withdrawal');

    // All visible patients restricted => normal empty data/zero totals, not a clinic block.
    $otherPatient=$actor(104,[],[],'client');
    $policy->save($otherPatient,104,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>true,'version'=>0],'schedule-empty');
    [$status,$data]=$call('/api/v1/dashboard?workspace=practitioner',$practitionerToken);$expect($status===200&&$data['data']['values']['my_appointments_today']===0&&$data['data']['values']['my_next_appointment']===null&&$data['data']['time_off_follow_ups']['count']===0,'All-restricted dashboard returns zeros/null');
    [$status,$data]=$call('/api/v1/practitioner/today',$practitionerToken);$expect($status===200&&$data['data']['appointments']===[],'All-restricted day returns an empty schedule');
    [$status,$data]=$call('/api/v1/practitioner/next-onsite',$practitionerToken);$expect($status===200&&$data['data']===null,'All-restricted next visit returns null');
    $policy->save($patientActor,101,['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>false,'version'=>$global+1],'schedule-global-restore');
    [$status,$data]=$call('/api/v1/practitioner/next-onsite',$practitionerToken);$expect($status===200&&(int)$data['data']['id']===$hidden,'Restoration immediately returns the newly permitted next visit');
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_schedule_directives');
    try{foreach(['/api/v1/dashboard?workspace=practitioner','/api/v1/dashboard?workspace=admin','/api/v1/practitioner/today','/api/v1/practitioner/visit-summary','/api/v1/practitioner/next-onsite','/api/v1/admin/schedule-exceptions'] as $path){[$status,,$cid,$raw]=$call($path,$practitionerToken);$expect($status>=400&&!str_contains($raw,'Synthetic Unrelated'),'Missing policy storage never returns patient data');}}
    finally{$pdo->exec('RENAME TABLE retained_schedule_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Filtered dashboard/schedule SQL/HTTP authorization: $checks checks passed.\n";
