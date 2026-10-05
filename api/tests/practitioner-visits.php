<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\PractitionerVisitService;

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$history=[
    ['event_code'=>'en_route','event_action'=>'record','occurred_at'=>'2026-09-29 12:00:00'],
    ['event_code'=>'arrived','event_action'=>'record','occurred_at'=>'2026-09-29 12:20:00'],
    ['event_code'=>'arrived','event_action'=>'undo','occurred_at'=>'2026-09-29 12:21:00'],
    ['event_code'=>'arrived','event_action'=>'record','occurred_at'=>'2026-09-29 12:24:00'],
];
$active=PractitionerVisitService::activeEvents($history);
$assert(count($active)===2&&$active['arrived']['occurred_at']==='2026-09-29 12:24:00','Milestone correction did not preserve the active event.');
$actor=new AuthContext(1,1,'','','','client',[]);
try{PractitionerVisitService::authorize($actor);throw new RuntimeException('Client gained access to practitioner visits.');}
catch(ApiException $error){$assert($error->status===403,'Wrong practitioner access error.');}
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$appointment=['delivery_mode'=>'mobile','status'=>'confirmed','starts_at'=>$now->format('Y-m-d H:i:s'),'ends_at'=>$now->modify('+1 hour')->format('Y-m-d H:i:s')];
PractitionerVisitService::assertMilestoneAllowed($appointment,'en_route','record');
$clinic=$appointment;$clinic['delivery_mode']='clinic';
try{PractitionerVisitService::assertMilestoneAllowed($clinic,'en_route','record');throw new RuntimeException('On-Site step accepted for clinic appointment.');}
catch(ApiException $error){$assert($error->status===422,'Wrong On-Site-only error.');}
$future=$appointment;$future['starts_at']=$now->modify('+1 day')->format('Y-m-d H:i:s');$future['ends_at']=$now->modify('+1 day +1 hour')->format('Y-m-d H:i:s');
try{PractitionerVisitService::assertMilestoneAllowed($future,'en_route','record');throw new RuntimeException('Early milestone accepted.');}
catch(ApiException $error){$assert($error->status===409,'Wrong visit-window error.');}
$counts=PractitionerVisitService::summaryCounts([
    ['id'=>1,'status'=>'completed','delivery_mode'=>'mobile','ends_at'=>'2026-09-29 12:00:00'],
    ['id'=>2,'status'=>'no_show','delivery_mode'=>'mobile','ends_at'=>'2026-09-29 13:00:00'],
    ['id'=>3,'status'=>'confirmed','delivery_mode'=>'clinic','ends_at'=>'2026-09-29 14:00:00'],
    ['id'=>4,'status'=>'confirmed','delivery_mode'=>'clinic','ends_at'=>'2026-09-29 17:00:00'],
],[
    1=>[
        ['event_code'=>'arrived','event_action'=>'record','occurred_at'=>'2026-09-29 11:00:00'],
        ['event_code'=>'left_residence','event_action'=>'record','occurred_at'=>'2026-09-29 12:00:00'],
        ['event_code'=>'left_residence','event_action'=>'undo','occurred_at'=>'2026-09-29 12:01:00'],
    ],
    3=>[
        ['event_code'=>'session_started','event_action'=>'record','occurred_at'=>'2026-09-29 13:00:00'],
        ['event_code'=>'session_started','event_action'=>'undo','occurred_at'=>'2026-09-29 13:01:00'],
    ],
],'2026-09-29 15:00:00');
$assert($counts===['scheduled_visits'=>4,'completed'=>1,'no_show'=>1,'awaiting_outcome'=>1,'visits_with_steps'=>1,'onsite_arrivals'=>1,'onsite_departures'=>0],'Seven-day counts must use current outcomes and active manual steps only.');
echo "Practitioner visit tests passed.\n";
