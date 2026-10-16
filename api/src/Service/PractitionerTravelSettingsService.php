<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class PractitionerTravelSettingsService
{
    private const FIELDS = ['mobile_radius_km','mobile_free_radius_km','travel_buffer_minutes','mobile_fee_cents'];

    public function __construct(private readonly Database $database) {}

    private function authorize(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('practitioner')) {
            throw new ApiException(403,'forbidden','Only practitioners can manage their own travel settings.');
        }
    }

    private function query(AuthContext $actor, ?int $serviceId = null, bool $lock = false): array
    {
        $sql = "SELECT ps.practitioner_id,ps.service_id,s.name,s.name_fr,ps.mobile_radius_km,ps.mobile_free_radius_km,ps.travel_buffer_minutes,ps.mobile_fee_cents
            FROM practitioner_services ps
            JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1
            JOIN users u ON u.id=p.user_id AND u.status='active' AND u.clinic_id=:clinic
            JOIN services s ON s.id=ps.service_id AND s.clinic_id=u.clinic_id AND s.active=1
            WHERE u.id=:user AND ps.active=1 AND ps.offers_mobile=1";
        $params = ['clinic'=>$actor->clinicId,'user'=>$actor->userId];
        if ($serviceId !== null) { $sql .= ' AND s.id=:service'; $params['service']=$serviceId; }
        $sql .= ' ORDER BY s.name,s.id'.($lock ? ' FOR UPDATE' : '');
        $q=$this->database->connection()->prepare($sql); $q->execute($params);
        return array_map($this->present(...),$q->fetchAll());
    }

    private function present(array $row): array
    {
        foreach (['practitioner_id','service_id',...self::FIELDS] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        // A content revision also detects administrator edits without another schema migration.
        $row['revision']=hash('sha256',json_encode([$row['practitioner_id'],$row['service_id'],...array_map(fn($key)=>$row[$key],self::FIELDS)],JSON_THROW_ON_ERROR));
        return $row;
    }

    public function get(AuthContext $actor): array
    {
        $this->authorize($actor);
        return ['clinic_id'=>$actor->clinicId,'items'=>$this->query($actor)];
    }

    public function save(AuthContext $actor, int $serviceId, array $body, string $correlation): array
    {
        $this->authorize($actor);
        if (array_diff(array_keys($body),[...self::FIELDS,'revision'])) {
            throw new ApiException(422,'validation_error','Only travel distances, fees and buffers can be changed here.');
        }
        $fields=[];
        foreach (self::FIELDS as $key) {
            if (!array_key_exists($key,$body) || (!is_int($body[$key]) && !($key==='mobile_free_radius_km' && $body[$key]===null))) {
                $fields[$key]='Enter a valid whole number.';
            }
        }
        if ($fields) throw new ApiException(422,'validation_error','Check your travel settings.',$fields);
        if ($body['mobile_radius_km']<1 || $body['mobile_radius_km']>500) $fields['mobile_radius_km']='Enter a distance from 1 to 500 km.';
        if ($body['mobile_free_radius_km']!==null && ($body['mobile_free_radius_km']<0 || $body['mobile_free_radius_km']>$body['mobile_radius_km'])) $fields['mobile_free_radius_km']='Free distance cannot exceed the maximum distance.';
        if ($body['travel_buffer_minutes']<0 || $body['travel_buffer_minutes']>240 || $body['travel_buffer_minutes']%15!==0) $fields['travel_buffer_minutes']='Use 15-minute increments from 0 to 240.';
        if ($body['mobile_fee_cents']<0 || $body['mobile_fee_cents']>10000000) $fields['mobile_fee_cents']='Enter a fee from 0 to 100000 CAD.';
        if ($fields) throw new ApiException(422,'validation_error','Check your travel settings.',$fields);
        if (!is_string($body['revision']??null) || !preg_match('/^[a-f0-9]{64}$/D',$body['revision'])) throw new ApiException(422,'validation_error','Reload your travel settings before saving.');
        $pdo=$this->database->connection();
        try {
            $pdo->beginTransaction();
            // Same lock order as assignment administration and booking confirmation.
            $pdo->prepare('SELECT id FROM clinics WHERE id=? FOR UPDATE')->execute([$actor->clinicId]);
            $row=$this->query($actor,$serviceId,true)[0]??null;
            if (!$row) throw new ApiException(404,'assignment_not_found','This On-Site service is no longer assigned to you in this clinic.');
            if (!hash_equals($row['revision'],$body['revision'])) throw new ApiException(409,'travel_settings_changed','Your travel settings changed. Reload them before saving.');
            $q=$pdo->prepare('UPDATE practitioner_services SET mobile_radius_km=:radius,mobile_free_radius_km=:free,travel_buffer_minutes=:buffer,mobile_fee_cents=:fee WHERE practitioner_id=:practitioner AND service_id=:service AND active=1 AND offers_mobile=1');
            $q->execute(['radius'=>$body['mobile_radius_km'],'free'=>$body['mobile_free_radius_km'],'buffer'=>$body['travel_buffer_minutes'],'fee'=>$body['mobile_fee_cents'],'practitioner'=>$row['practitioner_id'],'service'=>$serviceId]);
            (new AuditLogger($this->database))->write($actor->clinicId,$actor,$correlation,'practitioner.travel_settings.update','service',$serviceId,'success',[
                'practitioner_id'=>$row['practitioner_id'],'before'=>array_intersect_key($row,array_flip(self::FIELDS)),
                'after'=>array_intersect_key($body,array_flip(self::FIELDS)),
            ]);
            $saved=$this->query($actor,$serviceId)[0];
            $pdo->commit();
            return $saved;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
