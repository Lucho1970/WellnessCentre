<?php
declare(strict_types=1);
// Random isolated localhost database; includes recurrence regression and migration 044 rehearsal.
require __DIR__.'/recurring-bookings.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Http\ApiException;
use Wellness\Service\{AddressCoverageService,AdminService,AuditLogger,BookingService,OnsiteFee,OnsiteFeeService,SessionReceiptService,RecurringBookingService};
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/038_practitioner_private_locations.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/043_session_receipts.sql'));
$checks=0;
$assert=function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=function(callable $action,string $code)use($assert){try{$action();}catch(ApiException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
foreach(['onsite-distance-fees-preflight.sql','onsite-distance-fees-postflight.sql'] as $file){
 $sql=str_replace("'wellness_centre'","'$name'",file_get_contents(dirname(__DIR__,2).'/database/maintenance/'.$file));
 $sql=preg_replace('/^--.*$/m','',$sql);$sets=[];
 foreach(explode(';',$sql) as $statement)if(trim($statement)!==''){$q=$pdo->query($statement);$sets[]=$q->fetchAll();$q->closeCursor();}
 if(str_contains($file,'preflight'))$assert(count($sets[0])===9&&array_unique(array_column($sets[0],'prerequisite'))===['OK']&&(int)$sets[1][0]['new_columns_present']===6,'Packaged preflight detects installed migration');
 else $assert(count($sets[0])===6&&array_unique(array_column($sets[0],'result'))===['OK'],'Packaged postflight verifies columns');
}
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$policy=['mobile_free_radius_km'=>20,'mobile_radius_km'=>50,'mobile_fee_cents'=>1500];
foreach([0=>0,19999=>0,20000=>0,20001=>1500,50000=>1500] as $distance=>$fee)$assert(OnsiteFee::quote($policy,$distance)===$fee,'Exact distance boundary');
$deny(fn()=>OnsiteFee::quote($policy,50001),'outside_mobile_coverage');
$deny(fn()=>OnsiteFee::quote($policy,null),'coverage_validation_required');
$assert(OnsiteFee::quote(array_replace($policy,['mobile_free_radius_km'=>null]),null)===1500,'Legacy flat fee retained');
$pdo->exec("UPDATE locations SET address_line1='1 Test Street',city='Toronto',province='Ontario',postal_code='M1M 1M1' WHERE id=1;
 UPDATE practitioner_services SET offers_mobile=1,mobile_radius_km=50,mobile_free_radius_km=20,mobile_fee_cents=1500 WHERE practitioner_id=3 AND service_id=4");
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'','','','','',300,googleMapsApiKey:'synthetic',addressValidationSigningKey:str_repeat('k',32));
$meters=20000;
$transport=function(string $url,array $headers,array $payload)use(&$meters):array {
 if(str_contains($url,'computeRoutes'))return ['routes'=>[['distanceMeters'=>$meters]]];
 return ['result'=>['verdict'=>['addressComplete'=>true],'address'=>['postalAddress'=>['addressLines'=>['2 Test Street'],'locality'=>'Toronto','administrativeArea'=>'Ontario','postalCode'=>'M2M 2M2']],'geocode'=>['location'=>['latitude'=>43.7,'longitude'=>-79.4]]]];
};
$coverage=new AddressCoverageService($database,$config,$transport);
$bookings=new BookingService($database,new AuditLogger($database),$coverage);
$waivers=new OnsiteFeeService($database);
$practitioner=new AuthContext(2,1,'','','','staff',['practitioner']);
$colleague=new AuthContext(1,1,'','','','staff',['practitioner']);
$destination=['address_line1'=>'2 Test Street','address_line2'=>'','city'=>'Toronto','province'=>'Ontario','postal_code'=>'M2M 2M2','country'=>'Canada','instructions'=>''];
$request=['client_id'=>8,'location_id'=>1,'service_id'=>4,'practitioner_id'=>3,'duration_option_id'=>8,'delivery_mode'=>'mobile','destination'=>$destination,'quoted_base_price_cents'=>10000];
$quote=$coverage->validate($client,$request);
$assert($quote['mobile_fee_cents']===0&&!isset($quote['distance_km']),'Free quote; client origin distance remains private');
$request['starts_at']=(new DateTimeImmutable('now',new DateTimeZone('America/Toronto')))->modify('+80 days')->setTime(11,0)->format('Y-m-d\TH:i:sP');
$request['idempotency_key']='free-onsite-distance-session';$request['quoted_mobile_fee_cents']=0;$request['address_validation_token']=$coverage->validate($practitioner,$request)['token'];
$free=$bookings->create($practitioner,$request,'onsite-test');
$assert((int)$free['mobile_fee_cents']===0,'Free fee saved');
$assert((int)$pdo->query('SELECT mobile_distance_meters FROM appointments WHERE id='.(int)$free['id'])->fetchColumn()===20000,'Verified distance snapshot');
$meters=20001;$quote=$coverage->validate($practitioner,$request);
$assert($quote['mobile_fee_cents']===1500,'Flat fee immediately above free threshold');
$request['address_validation_token']=$quote['token'];
$request['starts_at']=(new DateTimeImmutable($request['starts_at']))->modify('+1 day')->format('Y-m-d\TH:i:sP');
$request['idempotency_key']='paid-onsite-distance-session';
$deny(fn()=>$bookings->create($practitioner,$request,'onsite-test'),'price_changed');
$request['quoted_mobile_fee_cents']=1500;$paid=$bookings->create($practitioner,$request,'onsite-test');
$assert((int)$paid['mobile_fee_cents']===1500,'Extended-distance fee saved');
$assert($bookings->create($practitioner,$request,'onsite-test')['id']===$paid['id'],'Confirmation retry stays idempotent');
$deny(fn()=>$waivers->waive($client,(int)$paid['id'],['version'=>1,'reason'=>'Local courtesy'],'test'),'forbidden');
$deny(fn()=>$waivers->waive($colleague,(int)$paid['id'],['version'=>1,'reason'=>'Local courtesy'],'test'),'receipt_not_found');
$deny(fn()=>$waivers->context(new AuthContext(2,2,'','','','staff',['super_admin']),(int)$paid['id']),'appointment_not_found');
$deny(fn()=>$waivers->waive($practitioner,(int)$paid['id'],['version'=>0,'reason'=>'Local courtesy'],'test'),'appointment_changed');
$waived=$waivers->waive($practitioner,(int)$paid['id'],['version'=>1,'reason'=>'Local courtesy'],'test');
$assert($waived['fee_cents']===0&&$waived['assessed_fee_cents']===1500&&$waived['waived']&&$waived['version']===2,'Waiver keeps original fee and advances version');
$assert((int)$pdo->query('SELECT mobile_fee_cents FROM appointments WHERE id='.(int)$free['id'])->fetchColumn()===0,'Other session unchanged');
$assert((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='appointment.travel_fee_waive'")->fetchColumn()===1,'Waiver audit');
$deny(fn()=>$waivers->waive($practitioner,(int)$paid['id'],['version'=>2,'reason'=>'Again'],'test'),'travel_fee_locked');
$pdo->exec('UPDATE practitioner_services SET mobile_fee_cents=2500 WHERE practitioner_id=3 AND service_id=4');
$deny(fn()=>$coverage->verifyBooking($practitioner,$request,$destination),'coverage_validation_mismatch');
$assert((int)$pdo->query('SELECT mobile_assessed_fee_cents FROM appointments WHERE id='.(int)$paid['id'])->fetchColumn()===1500,'Assignment changes preserve appointment price');
$meters=50001;$deny(fn()=>$coverage->validate($practitioner,$request),'outside_mobile_coverage');
$meters=50000;$quote=$coverage->validate($practitioner,$request);
$assert($quote['mobile_fee_cents']===2500,'Maximum distance included');
$request['address_validation_token']=$quote['token'];
$coverage->approve($admin,array_replace($request,['address_validation_token'=>$coverage->validate($admin,$request)['token']]),'test');
$assert(!$coverage->approvalStatus($practitioner,$request)['approved'],'Coverage-only approval cannot bypass tier pricing');
$without=$request;unset($without['address_validation_token']);
$deny(fn()=>$coverage->verifyBooking($practitioner,$without,$destination),'coverage_validation_required');
// New quotes price every occurrence; waiving one occurrence never affects its siblings.
$request['starts_at']=(new DateTimeImmutable($request['starts_at']))->modify('+10 days')->format('Y-m-d\TH:i:sP');
$request['idempotency_key']='tiered-recurring-series';$request['quoted_mobile_fee_cents']=2500;$request['recurrence']=['frequency'=>'weekly','count'=>2];
$series=new RecurringBookingService($database,$bookings);$preview=$series->create($practitioner,$request,'test',false);
$saved=$series->create($practitioner,$request+['preview_token'=>$preview['preview_token']],'test',true);
$assert($saved['applied']&&$saved['items'][0]['subtotal_cents']===12500&&$saved['items'][1]['subtotal_cents']===12500,'Recurring fee preview and saved totals agree');
$first=(int)$saved['items'][0]['appointment_id'];$second=(int)$saved['items'][1]['appointment_id'];
$waivers->waive($practitioner,$first,['version'=>1,'reason'=>'One visit only'],'test');
$assert((int)$pdo->query("SELECT mobile_fee_cents FROM appointments WHERE id=$second")->fetchColumn()===2500,'Sibling recurring fee unchanged');
// Persist and validate the actual admin assignment API, including rollback on invalid thresholds.
$administration=new AdminService($database,new AuditLogger($database));
$assignment=['location_ids'=>[1],'practitioners'=>[['practitioner_id'=>3,'offers_mobile'=>1,'offers_clinic'=>1,'mobile_radius_km'=>50,'mobile_free_radius_km'=>20,'mobile_fee_cents'=>1500,'travel_buffer_minutes'=>15]]];
$administration->updateServiceAssignments($admin,4,$assignment,'test');
$link=$administration->serviceAssignments($admin)['practitioners'][0];
$assert((int)$link['mobile_free_radius_km']===20&&(int)$link['mobile_fee_cents']===1500,'Admin saves and retrieves fee thresholds');
$invalid=$assignment;$invalid['practitioners'][0]['mobile_free_radius_km']=51;
$deny(fn()=>$administration->updateServiceAssignments($admin,4,$invalid,'test'),'validation_error');
$assert((int)$pdo->query('SELECT mobile_free_radius_km FROM practitioner_services WHERE practitioner_id=3 AND service_id=4')->fetchColumn()===20,'Invalid assignment rolled back');
// An active invoice blocks a waiver even before paid status is set.
$pdo->exec("INSERT INTO invoices(clinic_id,appointment_id,client_id,invoice_number,subtotal_cents,tax_cents,total_cents,balance_cents,status) VALUES(1,$second,8,'SYNTHETIC-TRAVEL',12500,0,12500,12500,'draft')");
$deny(fn()=>$waivers->waive($practitioner,$second,['version'=>1,'reason'=>'Already invoiced'],'test'),'travel_fee_locked');
// A waived completed session issues a zero-travel receipt and locks further changes.
$id=(int)$paid['id'];$pdo->exec("UPDATE appointments SET status='completed',starts_at='2026-01-02 15:00:00',ends_at='2026-01-02 16:00:00',buffer_starts_at='2026-01-02 15:00:00',buffer_ends_at='2026-01-02 16:00:00' WHERE id=$id");
$_ENV['SESSION_RECEIPTS_ENABLED']='true';$receipts=new SessionReceiptService($database,new AuditLogger($database));
$receipts->saveTemplate($admin,['version'=>0,'issuer_name'=>'Test clinic','issuer_address'=>'1 Test Street','issuer_contact'=>'clinic@example.test'],'test');
$receipts->saveProfile($practitioner,$id,['version'=>0,'practitioner_name'=>'Test practitioner','designation'=>'Test designation','registration_number'=>''],'test');
$r=$receipts->issue($practitioner,$id,['version'=>2,'template_version'=>1,'profile_version'=>1,'tax_cents'=>0,'amount_cents'=>10000,'method'=>'cash','paid_on'=>'2026-01-02','confirmed'=>true,'idempotency_key'=>'onsite-waived-receipt'],'test');
$snapshot=json_decode($pdo->query('SELECT snapshot FROM session_receipts WHERE id='.(int)$r['id'])->fetchColumn(),true);
$assert($snapshot['travel_cents']===0&&$snapshot['travel_assessed_cents']===1500&&$snapshot['travel_fee_waived']&&$r['total_cents']===10000,'Receipt reflects waived fee and preserves original assessment');
$assert(str_starts_with(base64_decode($receipts->pdf($client,(int)$r['id'],'test')['pdf_base64']),'%PDF-'),'Waived PDF available in client portal');
$deny(fn()=>$waivers->waive($practitioner,$id,['version'=>3,'reason'=>'After payment'],'test'),'travel_fee_locked');
echo "$checks On-Site distance and waiver checks passed.\n";
