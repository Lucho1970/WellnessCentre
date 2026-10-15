<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class SessionReceiptService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}
    public static function enabled(): bool {return filter_var($_ENV['SESSION_RECEIPTS_ENABLED']??getenv('SESSION_RECEIPTS_ENABLED')?:'false',FILTER_VALIDATE_BOOL);}
    private function guard(AuthContext $actor,bool $write=false): void
    {
        if(!self::enabled())throw new ApiException(503,'receipts_disabled','Session receipts are not enabled.');
        if($actor->userType==='client'&&!$write)return;
        if($actor->userType!=='staff'||!$actor->hasAnyRole('practitioner','super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Receipt access is not permitted.');
    }
    public static function assertAccess(AuthContext $actor,array $row): void
    {
        $allowed=$actor->clinicId===(int)$row['clinic_id']&&($actor->userType==='client'
            ? $actor->userId===(int)$row['client_id']
            : ($actor->userType==='staff'&&($actor->hasAnyRole('super_admin','clinic_admin')||($actor->hasAnyRole('practitioner')&&$actor->userId===(int)$row['practitioner_user_id']))));
        if(!$allowed)throw new ApiException(404,'receipt_not_found','Receipt not found.');
    }
    private function appointment(AuthContext $actor,int $id,bool $lock=false): array
    {
        $q=$this->database->connection()->prepare('SELECT a.*,p.user_id practitioner_user_id,u.display_name practitioner_name,c.display_name client_name,s.name service_name,l.timezone FROM appointments a JOIN practitioners p ON p.id=a.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=a.clinic_id JOIN users c ON c.id=a.client_id AND c.clinic_id=a.clinic_id JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id WHERE a.id=? AND a.clinic_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$actor->clinicId]);$row=$q->fetch();if(!$row)throw new ApiException(404,'receipt_not_found','Receipt not found.');self::assertAccess($actor,$row);return $row;
    }
    private function receipt(AuthContext $actor,int $id,bool $lock=false): array
    {
        $q=$this->database->connection()->prepare('SELECT r.*,CAST(r.snapshot AS BINARY) snapshot,p.user_id practitioner_user_id FROM session_receipts r JOIN practitioners p ON p.id=r.practitioner_id WHERE r.id=? AND r.clinic_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,$actor->clinicId]);$row=$q->fetch();
        if(!$row)throw new ApiException(404,'receipt_not_found','Receipt not found.');self::assertAccess($actor,$row);return $row;
    }
    private static function summary(array $row): array
    {
        $s=json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR);
        return ['id'=>(int)$row['id'],'number'=>$row['receipt_number'],'appointment_id'=>(int)$row['appointment_id'],'status'=>$row['status'],'issued_at'=>$row['issued_at'],'service_name'=>$s['service_name'],'practitioner_name'=>$s['practitioner_name'],'total_cents'=>$s['total_cents'],'void_reason'=>$row['void_reason']];
    }
    private function settings(AuthContext $actor,?array $a=null): array
    {
        $pdo=$this->database->connection();$q=$pdo->prepare('SELECT version,CAST(settings AS BINARY) settings FROM receipt_templates WHERE clinic_id=?');$q->execute([$actor->clinicId]);$template=$q->fetch();
        $profile=false;if($a){$q=$pdo->prepare('SELECT version,CAST(settings AS BINARY) settings FROM practitioner_receipt_profiles WHERE clinic_id=? AND practitioner_id=? AND service_id=?');$q->execute([$actor->clinicId,$a['practitioner_id'],$a['service_id']]);$profile=$q->fetch();}
        return ['template'=>$template?json_decode($template['settings'],true,32,JSON_THROW_ON_ERROR):null,'template_version'=>$template?(int)$template['version']:0,'profile'=>$profile?json_decode($profile['settings'],true,32,JSON_THROW_ON_ERROR):null,'profile_version'=>$profile?(int)$profile['version']:0];
    }
    private static function text(array $body,string $key,int $max,bool $required=true): string
    {
        $v=$body[$key]??'';if(!is_string($v)||mb_strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v)||($required&&trim($v)===''))throw new ApiException(422,'validation_error','Complete the required receipt fields within their length limits.');return trim($v);
    }
    public static function logo(mixed $value): string
    {
        if($value===''||$value===null)return '';
        if(!is_string($value)||strlen($value)>210000||!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#D',$value,$m)||!($bytes=base64_decode($m[2],true))||strlen($bytes)>150000)throw new ApiException(422,'validation_error','Use a PNG or JPEG logo smaller than 150 KB.');
        $info=@getimagesizefromstring($bytes);if(!$info||$info[0]>1600||$info[1]>1600||$info[0]<1||$info[1]<1||$info['mime']!=='image/'.$m[1])throw new ApiException(422,'validation_error','Use a valid PNG or JPEG logo up to 1600 pixels per side.');
        if($m[1]==='png'&&!extension_loaded('gd'))throw new ApiException(422,'validation_error','PNG receipts require the GD extension. Use a JPEG logo on this server.');return $value;
    }
    public function saveTemplate(AuthContext $actor,array $body,string $correlation): array
    {
        $this->guard($actor,true);if(!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Only clinic administrators may maintain the receipt template.');
        $settings=['issuer_name'=>self::text($body,'issuer_name',150),'issuer_address'=>self::text($body,'issuer_address',300),'issuer_contact'=>self::text($body,'issuer_contact',190),'clinic_logo'=>self::logo($body['clinic_logo']??'')];
        return $this->saveSettings($actor,$body,$settings,null,$correlation);
    }
    public function saveProfile(AuthContext $actor,int $appointmentId,array $body,string $correlation): array
    {
        $this->guard($actor,true);$a=$this->appointment($actor,$appointmentId);
        $settings=['practitioner_name'=>self::text($body,'practitioner_name',150),'designation'=>self::text($body,'designation',100),'registration_number'=>self::text($body,'registration_number',100,false),'practitioner_logo'=>self::logo($body['practitioner_logo']??'')];
        return $this->saveSettings($actor,$body,$settings,$a,$correlation);
    }
    private function saveSettings(AuthContext $actor,array $body,array $settings,?array $a,string $correlation): array
    {
        if(!is_int($body['version']??null)||$body['version']<0)throw new ApiException(422,'validation_error','A saved settings version is required.');
        $pdo=$this->database->connection();$version=$body['version'];$json=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        try{$pdo->beginTransaction();
            // Serialize first inserts and updates with receipt issuance in this clinic.
            $q=$pdo->prepare('SELECT id FROM clinics WHERE id=? FOR UPDATE');$q->execute([$actor->clinicId]);
            $current=$this->settings($actor,$a);if($current[$a?'profile_version':'template_version']!==$version)throw new ApiException(409,'receipt_settings_changed','Receipt settings changed. Refresh and try again.');
            if($a){$q=$pdo->prepare('INSERT INTO practitioner_receipt_profiles(clinic_id,practitioner_id,service_id,settings,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');$q->execute([$actor->clinicId,$a['practitioner_id'],$a['service_id'],$json,$actor->userId]);}
            else{$q=$pdo->prepare('INSERT INTO receipt_templates(clinic_id,settings,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');$q->execute([$actor->clinicId,$json,$actor->userId]);}
            $this->audit->write($actor->clinicId,$actor,$correlation,$a?'receipt.profile_save':'receipt.template_save',$a?'practitioner':'clinic',$a?(int)$a['practitioner_id']:$actor->clinicId);$pdo->commit();return ['version'=>$version+1];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function context(AuthContext $actor,int $appointmentId): array
    {
        if(!self::enabled())return ['enabled'=>false];
        $this->guard($actor,true);$a=$this->appointment($actor,$appointmentId);
        $q=$this->database->connection()->prepare('SELECT session_receipts.*,CAST(snapshot AS BINARY) snapshot FROM session_receipts WHERE clinic_id=? AND appointment_id=? ORDER BY id DESC LIMIT 25');$q->execute([$actor->clinicId,$appointmentId]);
        return $this->settings($actor,$a)+['enabled'=>true,'can_edit_template'=>$actor->hasAnyRole('super_admin','clinic_admin'),'appointment'=>['id'=>$appointmentId,'version'=>(int)$a['version'],'status'=>$a['status'],'client_name'=>$a['client_name'],'practitioner_name'=>$a['practitioner_name'],'service_name'=>$a['service_name'],'base_cents'=>$a['base_price_cents']===null?null:(int)$a['base_price_cents'],'travel_cents'=>(int)$a['mobile_fee_cents']], 'items'=>array_map(self::summary(...),$q->fetchAll())];
    }
    public function list(AuthContext $actor,array $query): array
    {
        if(!self::enabled())return ['enabled'=>false,'items'=>[],'has_more'=>false];
        $this->guard($actor);$page=ClientOverviewService::page($query);$scope='';$params=[$actor->clinicId];
        if($actor->userType==='client'){$scope=' AND r.client_id=?';$params[]=$actor->userId;}
        elseif(!$actor->hasAnyRole('super_admin','clinic_admin')){$scope=' AND p.user_id=?';$params[]=$actor->userId;}
        $offset=($page-1)*20;$q=$this->database->connection()->prepare("SELECT r.id,r.receipt_number,r.appointment_id,r.status,r.issued_at,r.void_reason,CAST(r.snapshot AS BINARY) snapshot FROM session_receipts r JOIN practitioners p ON p.id=r.practitioner_id WHERE r.clinic_id=? $scope ORDER BY r.id DESC LIMIT 21 OFFSET $offset");$q->execute($params);$rows=$q->fetchAll();$more=count($rows)>20;
        return ['enabled'=>true,'items'=>array_map(function(array $row)use($actor): array {$item=self::summary($row);if($actor->userType==='client')$item['void_reason']=null;return $item;},array_slice($rows,0,20)),'has_more'=>$more];
    }
    public static function validated(array $body): array
    {
        $out=[];
        foreach(['issuer_name'=>150,'issuer_address'=>300,'issuer_contact'=>190,'designation'=>100,'registration_number'=>100,'idempotency_key'=>100] as $key=>$max){
            $value=$body[$key]??'';if(!is_string($value)||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Check the receipt details.');
            $out[$key]=trim($value);if($key!=='registration_number'&&$out[$key]==='')throw new ApiException(422,'validation_error','Complete the issuer and professional details.');
        }
        if(!preg_match('/^[a-zA-Z0-9-]{16,100}$/D',$out['idempotency_key']))throw new ApiException(422,'validation_error','A valid receipt request key is required.');
        foreach(['version','tax_cents','amount_cents'] as $key){if(!is_int($body[$key]??null)||$body[$key]<0||$body[$key]>100000000)throw new ApiException(422,'validation_error','Enter valid amounts in cents.');$out[$key]=$body[$key];}
        if(!in_array($body['method']??null,['cash','debit','credit','etransfer','other'],true))throw new ApiException(422,'validation_error','Choose a manual payment method.');$out['method']=$body['method'];
        if(!in_array($body['issuer_type']??null,['practitioner','clinic'],true)||($body['confirmed']??null)!==true)throw new ApiException(422,'validation_error','Confirm the issuer, applicable credentials, tax and payment details.');$out['issuer_type']=$body['issuer_type'];
        $date=$body['paid_on']??'';if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date))||$parsed->format('Y-m-d')!==$date)throw new ApiException(422,'validation_error','Enter a valid payment date.');$out['paid_on']=$date;
        $out['language']=($body['language']??'en')==='fr'?'fr':'en';return $out;
    }
    public function issue(AuthContext $actor,int $appointmentId,array $body,string $correlation): array
    {
        $this->guard($actor,true);$pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM clinics WHERE id=? FOR UPDATE');$q->execute([$actor->clinicId]);$a=$this->appointment($actor,$appointmentId,true);
            // Hash the submitted intent rather than current settings: a network retry after a template edit still retrieves the original receipt.
            $intent=$body;ksort($intent);$hash=hash('sha256',json_encode($intent,JSON_THROW_ON_ERROR));
            $key=self::text($body,'idempotency_key',100);
            $q=$pdo->prepare('SELECT session_receipts.*,CAST(snapshot AS BINARY) snapshot FROM session_receipts WHERE clinic_id=? AND request_key=?');$q->execute([$actor->clinicId,$key]);$existing=$q->fetch();
            if($existing){if((int)$existing['appointment_id']!==$appointmentId||!hash_equals($existing['request_hash'],$hash))throw new ApiException(409,'receipt_request_changed','This request key was already used for different details.');$pdo->commit();return self::summary($existing);}
            $settings=$this->settings($actor,$a);
            if(!$settings['template']||!$settings['profile'])throw new ApiException(409,'receipt_setup_required','Save the clinic template and practitioner service details before issuing a receipt.');
            if(($body['template_version']??null)!==$settings['template_version']||($body['profile_version']??null)!==$settings['profile_version'])throw new ApiException(409,'receipt_settings_changed','Receipt settings changed. Refresh and review the updated details.');
            $data=self::validated(array_merge($body,$settings['template'],$settings['profile'],['issuer_type'=>'clinic']));
            if($a['status']!=='completed'||(int)$a['version']!==$data['version'])throw new ApiException(409,'receipt_session_changed','Refresh and select a completed session before issuing a receipt.');
            $q=$pdo->prepare("SELECT id FROM invoices WHERE clinic_id=? AND appointment_id=? AND status NOT IN ('void','refunded')");$q->execute([$actor->clinicId,$appointmentId]);if($q->fetchColumn())throw new ApiException(409,'receipt_existing_invoice','This session already has an invoice. Review its billing before creating another.');
            $q=$pdo->prepare("SELECT id FROM session_receipts WHERE clinic_id=? AND appointment_id=? AND status='issued'");$q->execute([$actor->clinicId,$appointmentId]);if($q->fetchColumn())throw new ApiException(409,'receipt_already_issued','A receipt has already been issued for this session.');
            if($a['base_price_cents']===null)throw new ApiException(409,'receipt_price_missing','This session has no historical price. Resolve its price before billing.');
            $base=(int)$a['base_price_cents'];$travel=(int)$a['mobile_fee_cents'];$total=$base+$travel+$data['tax_cents'];
            if($total<1||$total>100000000||$data['amount_cents']!==$total)throw new ApiException(422,'receipt_full_payment_required','This first receipt workflow requires full payment of the session total.');
            $zone=new DateTimeZone($a['timezone']);$today=(new DateTimeImmutable('now',$zone))->format('Y-m-d');$service=(new DateTimeImmutable($a['starts_at'],new DateTimeZone('UTC')))->setTimezone($zone);
            if($data['paid_on']>$today||$data['paid_on']<$service->format('Y-m-d'))throw new ApiException(422,'receipt_payment_date','Payment date must be between the service date and today.');
            $number='R-'.$actor->clinicId.'-'.strtoupper(bin2hex(random_bytes(8)));$issued=gmdate('Y-m-d H:i:s');
            $methodLabels=['en'=>['cash'=>'Cash','debit'=>'Debit','credit'=>'Credit card','etransfer'=>'E-transfer','other'=>'Other'],'fr'=>['cash'=>'Espèces','debit'=>'Débit','credit'=>'Carte de crédit','etransfer'=>'Virement électronique','other'=>'Autre']];
            $snapshot=$data+['number'=>$number,'issued_at'=>$issued.' UTC','client_name'=>$a['client_name'],'practitioner_name'=>$settings['profile']['practitioner_name'],'clinic_logo'=>$settings['template']['clinic_logo'],'practitioner_logo'=>$settings['profile']['practitioner_logo'],'template_version'=>$settings['template_version'],'profile_version'=>$settings['profile_version'],'service_name'=>$a['service_name'],'service_date'=>$service->format('Y-m-d H:i'),'timezone'=>$a['timezone'],'delivery_mode'=>$a['delivery_mode'],'duration_minutes'=>(int)round((strtotime($a['ends_at'].' UTC')-strtotime($a['starts_at'].' UTC'))/60),'base_cents'=>$base,'travel_cents'=>$travel,'tax_cents'=>$data['tax_cents'],'total_cents'=>$total,'balance_cents'=>0,'method_label'=>$methodLabels[$data['language']][$data['method']]];
            unset($snapshot['idempotency_key'],$snapshot['version'],$snapshot['confirmed']);
            $pdf=ReceiptPdf::render($snapshot);if(strlen($pdf)>2000000)throw new ApiException(500,'receipt_pdf_size','Unable to generate receipt.');
            $q=$pdo->prepare("INSERT INTO invoices(clinic_id,client_id,appointment_id,invoice_number,status,currency,subtotal_cents,tax_cents,total_cents,balance_cents,issued_at) VALUES(?,?,?,?,'paid','CAD',?,?,?,0,?)");$q->execute([$actor->clinicId,$a['client_id'],$appointmentId,$number,$base+$travel,$data['tax_cents'],$total,$issued]);$invoice=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('INSERT INTO invoice_line_items(invoice_id,service_id,description,quantity,unit_price_cents,tax_cents,line_total_cents) VALUES(?,?,?,1,?,?,?)');$q->execute([$invoice,$a['service_id'],$a['service_name'],$base+$travel,$data['tax_cents'],$total]);
            // The manual entry captures a local calendar date, not a claimed payment time.
            $paid=(new DateTimeImmutable($data['paid_on'].' 00:00:00',$zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $q=$pdo->prepare("INSERT INTO payments(invoice_id,amount_cents,currency,method,status,idempotency_key,paid_at,recorded_by) VALUES(?,?,'CAD',?,'completed',?,?,?)");$q->execute([$invoice,$total,$data['method'],'receipt-'.hash('sha256',$actor->clinicId.':'.$data['idempotency_key']),$paid,$actor->userId]);$payment=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('INSERT INTO session_receipts(clinic_id,appointment_id,client_id,practitioner_id,invoice_id,payment_id,receipt_number,request_key,request_hash,snapshot,pdf_data,issued_by,issued_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$actor->clinicId,$appointmentId,$a['client_id'],$a['practitioner_id'],$invoice,$payment,$number,$data['idempotency_key'],$hash,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$pdf,$actor->userId,$issued]);$id=(int)$pdo->lastInsertId();
            // A paid session cannot subsequently be reopened as an unbilled visit.
            $q=$pdo->prepare("UPDATE appointments SET status='paid',version=version+1 WHERE id=? AND clinic_id=?");$q->execute([$appointmentId,$actor->clinicId]);
            $q=$pdo->prepare("INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id,reason) VALUES(?,'completed','paid',?,'Manual payment and receipt issued')");$q->execute([$appointmentId,$actor->userId]);
            $this->audit->write($actor->clinicId,$actor,$correlation,'receipt.issue','session_receipt',$id,'success',['appointment_id'=>$appointmentId,'invoice_id'=>$invoice,'payment_id'=>$payment]);
            $row=$this->receipt($actor,$id);$pdo->commit();return self::summary($row);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function pdf(AuthContext $actor,int $id,string $correlation): array
    {
        $this->guard($actor);$r=$this->receipt($actor,$id);if($r['status']!=='issued')throw new ApiException(409,'receipt_void','This receipt has been voided.');
        $this->audit->write($actor->clinicId,$actor,$correlation,'receipt.download','session_receipt',$id);
        return ['filename'=>$r['receipt_number'].'.pdf','pdf_base64'=>base64_encode($r['pdf_data'])];
    }
    public function void(AuthContext $actor,int $id,array $body,string $correlation): array
    {
        $this->guard($actor,true);$reason=$body['reason']??'';$kind=$body['kind']??'';
        if(!is_string($reason)||strlen(trim($reason))<5||strlen($reason)>500||!in_array($kind,['recording_error','refunded'],true)||($body['confirmed']??false)!==true)throw new ApiException(422,'validation_error','Confirm a recording correction or an already completed full refund, with a reason.');
        $pdo=$this->database->connection();try{
            $pdo->beginTransaction();
            // Same lock order as issuance: appointment first, then receipt.
            $initial=$this->receipt($actor,$id);$a=$this->appointment($actor,(int)$initial['appointment_id'],true);$r=$this->receipt($actor,$id,true);
            if($r['status']==='void'){$pdo->commit();return ['void'=>true];}
            if($kind==='refunded'){$q=$pdo->prepare("INSERT INTO refunds(payment_id,amount_cents,reason,status,processed_by,processed_at) SELECT id,amount_cents,?,'completed',?,UTC_TIMESTAMP() FROM payments WHERE id=?");$q->execute([$reason,$actor->userId,$r['payment_id']]);}
            $q=$pdo->prepare('UPDATE payments SET status=? WHERE id=?');$q->execute([$kind==='refunded'?'refunded':'failed',$r['payment_id']]);
            $q=$pdo->prepare('UPDATE invoices SET status=?,balance_cents=0 WHERE id=?');$q->execute([$kind==='refunded'?'refunded':'void',$r['invoice_id']]);
            $q=$pdo->prepare("UPDATE session_receipts SET status='void',voided_by=?,voided_at=UTC_TIMESTAMP(),void_reason=? WHERE id=?");$q->execute([$actor->userId,$reason,$id]);
            $q=$pdo->prepare("UPDATE appointments SET status='completed',version=version+1 WHERE id=? AND clinic_id=? AND status='paid'");$q->execute([$a['id'],$actor->clinicId]);
            $q=$pdo->prepare("INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id,reason) VALUES(?,'paid','completed',?,'Receipt voided; see financial audit')");$q->execute([$a['id'],$actor->userId]);
            $this->audit->write($actor->clinicId,$actor,$correlation,'receipt.void','session_receipt',$id,'success',['kind'=>$kind]);$pdo->commit();return ['void'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
