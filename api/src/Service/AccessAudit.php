<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\ApplicationAdminContext;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Http\Request;

/** API disclosure/denial trail. Never serializes request data or arbitrary response fields. */
final class AccessAudit
{
    private string $actorType = 'anonymous';
    private ?int $actorId = null;
    private ?int $actorClinic = null;
    private const CONTAINERS = [
        'categories','taxes','capabilities','rooms','data','items','versions','appointments','appointment','clients','client','practitioners','practitioner',
        'users','user','profile','events','notes','note','messages','forms','tasks','receipts','notifications',
        'invitations','claims','entries','series','history','changes','widgets','results','records','amendments',
        'upcoming','recent','next_appointment','follow_ups','time_off_follow_ups','combinations','locations','services',
        'staff','qualifications','types','clinics','credentials','customer_links','survivor','duplicate','memberships',
    ];
    private const IDS = [
        'qualification_type_id'=>'qualification_type','existing_user_id'=>'user','widget_id'=>'dashboard_widget','target_staff_id'=>'user','client_id'=>'client','user_id'=>'user','practitioner_id'=>'practitioner','appointment_id'=>'appointment',
        'form_template_id'=>'form_template','submission_id'=>'form_submission','task_id'=>'client_form_task',
        'receipt_id'=>'session_receipt','invoice_id'=>'invoice','payment_id'=>'payment','enquiry_id'=>'travel_enquiry',
        'notification_id'=>'notification','series_id'=>'recurring_series','recurring_series_id'=>'recurring_series',
        'invitation_id'=>'invitation','identity_id'=>'customer_identity','location_id'=>'location','service_id'=>'service',
        'sender_id'=>'user','author_id'=>'user','author_user_id'=>'user','actor_user_id'=>'user','claim_id'=>'claim',
        'clinic_id'=>'clinic','global_practitioner_id'=>'global_practitioner','membership_id'=>'staff_membership',
        'category_id'=>'service_category','approval_id'=>'address_coverage_approval','capability_id'=>'room_capability','room_id'=>'room','staff_invitation_id'=>'staff_invitation',
        'amendment_id'=>'form_amendment',
    ];
    private const ENTITIES = [
        'dashboard-widgets'=>'dashboard_widget','reminder-schedules'=>'reminder_schedule','reviewers'=>'user','privacy-audit'=>'audit_event','clients'=>'client','booking-clients'=>'client','appointments'=>'appointment','today'=>'appointment',
        'next-onsite'=>'appointment','calendar'=>'appointment','receipts'=>'session_receipt','receipt'=>'session_receipt',
        'forms'=>'client_form_task','tasks'=>'client_form_task','templates'=>'form_template','drafts'=>'form_draft',
        'notifications'=>'notification','travel-enquiries'=>'travel_enquiry','messages'=>'travel_enquiry_message',
        'practitioners'=>'practitioner','staff'=>'user','users'=>'user','invitations'=>'invitation',
        'staff-invitations'=>'staff_invitation','recurring-series'=>'recurring_series','clinics'=>'clinic',
        'service-categories'=>'service_category','categories'=>'service_category','taxes'=>'tax','service-assignments'=>'service','catalogue-settings'=>'record','room-capabilities'=>'room_capability','capabilities'=>'room_capability','room-capability-assignments'=>'room_capability','room-practitioner-restrictions'=>'room','locations'=>'location','services'=>'service','logistics-notes'=>'appointment_logistics_note',
        'visit-history'=>'visit_event','history'=>'history_event','practitioner-access'=>'practitioner',
        'global-credentials'=>'global_credential','qualifications'=>'qualification','rooms'=>'room',
        'practitioner-enquiries'=>'practitioner_enquiry','form-library'=>'form_library_entry','library'=>'form_library_entry',
        'amendments'=>'form_amendment',
        'consent'=>'consent_directive','types'=>'qualification_type','credentials'=>'global_credential','memberships'=>'staff_membership',
    ];

    public function __construct(private readonly Database $database) {}

    public static function resourceTypes(): array
    {return array_values(array_unique(array_merge(array_values(self::ENTITIES),array_values(self::IDS),['record','audit_event','time_off','event'])));}

    public function actor(AuthContext $actor): void
    {
        $this->actorType = $actor->userType === 'client' ? 'client' : 'staff';
        $this->actorId = $actor->userId;
        $this->actorClinic = $actor->clinicId;
    }

    public function customerIdentity(int $id, int $clinic): void
    {
        $this->actorType = 'customer_identity'; $this->actorId = $id; $this->actorClinic = $clinic;
    }

    public function applicationActor(ApplicationAdminContext $actor): void
    {
        $this->actorType = 'application_admin'; $this->actorId = $actor->id; $this->actorClinic = null;
    }

    /** Canonical route and numeric targets only; unknown path segments are never retained. */
    public static function describe(?Request $request): array
    {
        if ($request === null) return ['route'=>'unparsed','entity'=>'record','targets'=>[]];
        // This is a vocabulary, not a route authorization list. New words safely become :value.
        $words = array_merge(array_keys(self::ENTITIES), [
            'api','v1','customer','application','admin','practitioner','profile','auth','me','options','activity',
            'session','challenge','logout','register','accept','search','preview','availability','booking-options',
            'cancellation-preview','reassignment-options','address','address-coverage','validate','approval',
            'approve','revoke','resolve','appointment-links','action-links','pdf','download','merge-preview','merge',
            'dashboard','preferences','visit-summary','travel-estimate','qualifications','types','public-card',
            'avatar','work-location','travel-settings','global-credentials','discovery-preferences','discovery',
            'form-library','library','source','versions','review','copy','submit','amendments','draft','notify',
            'practitioner-enquiries','settings','template','health','database','public','site-config',
        ]);
        $segments = explode('/', trim($request->path, '/')); $safe = []; $targets = []; $entity = 'record';
        foreach ($segments as $index=>$segment) {
            if (isset(self::ENTITIES[$segment])) $entity = self::ENTITIES[$segment];
            $id = self::positiveId($segment);
            if ($id !== null) {
                $safe[] = ':id';
                $type = self::ENTITIES[$segments[$index-1] ?? ''] ?? $entity;
                if ($type === 'practitioner' && str_starts_with($request->path,'/api/v1/application/practitioners/')) $type = 'global_practitioner';
                // A widget restore URL contains a version ordinal, not a resource ID.
                if(!(str_contains($request->path,'/dashboard-widgets/')&&($segments[$index-1]??'')==='versions'))$targets[$type.':'.$id] = ['type'=>$type,'id'=>$id];
            } else $safe[] = in_array($segment,$words,true) ? $segment : ':value';
        }
        // Calendar mixes appointments and time off; nested response kinds refine the type.
        if (str_contains($request->path,'appointment-links/resolve')) $entity = 'appointment';
        if (preg_match('#/forms/templates/[0-9]+/history$#',$request->path)) $entity = 'form_template';
        if (preg_match('#/(customer/)?profile$#',$request->path)) $entity = 'user';
        if (str_ends_with($request->path,'/auth/me')) $entity = 'user';
        if (str_starts_with($request->path,'/api/v1/application/practitioners')) $entity = 'global_practitioner';
        return ['route'=>substr('/'.implode('/',$safe),0,240),'entity'=>$entity,'targets'=>array_values($targets)];
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/^[0-9]+$/D',$value)) $value = ltrim($value,'0') ?: '0';
        $id = filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id === false ? null : $id;
    }

    /** Walk structural containers, never answers, message bodies, definitions, tokens or payload JSON. */
    public static function resources(array $data, string $entity): array
    {
        $found = [];
        $visit = function(array $node,string $type,int $depth) use (&$visit,&$found): void {
            if ($depth > 24) throw new \RuntimeException('Audit structure exceeds supported depth.');
            if (array_is_list($node)) {
                foreach ($node as $item) if (is_array($item)) $visit($item,$type,$depth+1);
                return;
            }
            if (($node['kind'] ?? null) === 'time_off') $type = 'time_off';
            if (($node['kind'] ?? null) === 'appointment') $type = 'appointment';
            foreach ($node as $key=>$value) {
                if ($key === 'id' || isset(self::IDS[$key])) {
                    $id = self::positiveId($value);
                    $recordType = $key === 'id' ? $type : self::IDS[$key];
                    if ($id !== null) $found[$recordType.':'.$id] = ['type'=>$recordType,'id'=>$id];
                }
                if (is_array($value) && in_array($key,self::CONTAINERS,true) && ($key!=='versions'||$type==='dashboard_widget')) {
                    $next = self::ENTITIES[$key] ?? match($key) {
                        'client'=>'client','appointment'=>'appointment','practitioner'=>'practitioner',
                        'user','profile','staff'=>'user','notes','note'=>'appointment_logistics_note','events'=>'event',
                        'survivor','duplicate'=>'client',
                        default=>$type,
                    };
                    $visit($value,$next,$depth+1);
                }
            }
        };
        $visit($data,$entity,0);
        // Dashboard values have administrator-chosen widget keys. Only the fixed projection
        // schema may expose appointment records; do not recursively inspect definitions.
        $definitions = $data['definitions'] ?? [];
        foreach (is_array($definitions) ? $definitions : [] as $definition) {
            if (is_array($definition) && ($definition['dataProjection'] ?? null) === 'next_appointment') {
                $value = $data['values'][$definition['id'] ?? ''] ?? null;
                if (is_array($value)) $visit($value,'appointment',0);
            }
        }
        return array_values($found);
    }

    /** Invoked once before any JSON body is emitted. Failed audit writes block successful disclosures. */
    public function response(?Request $request,array $payload,int $status,?int $resolvedClinic): void
    {
        if (($request?->method ?? '') === 'OPTIONS') return;
        if ($status < 400 && $this->actorType === 'anonymous') return; // Published catalogue/health responses.
        $description = self::describe($request);
        $requested = [];
        // These are requested targets, never evidence of identity, ownership or existence.
        foreach (['room_id','service_id','capability_id','client_id','appointment_id','practitioner_id','task_id','receipt_id','series_id','enquiry_id','user_id'] as $key) {
            foreach ([$request?->body[$key] ?? null,$request?->query[$key] ?? null] as $value) {
                $id = self::positiveId($value);
                if ($id !== null) $requested[self::IDS[$key].':'.$id] = ['type'=>self::IDS[$key],'id'=>$id];
            }
        }
        $attempted = $requested;
        foreach ($description['targets'] as $target) $attempted[$target['type'].':'.$target['id']] = $target;
        $method = in_array($request?->method,['GET','POST','PUT','PATCH','DELETE','HEAD'],true) ? $request->method : 'OTHER';
        $code = $payload['error']['code'] ?? null;
        $code = is_string($code) && preg_match('/^[a-z_]{1,80}$/D',$code) ? $code : null;
        $denied = in_array($status,[401,403,404],true) || in_array($code,['invalid_client','client_registration_review'],true);
        $outcome = $status < 400 ? 'success' : ($denied ? 'denied' : 'failure');
        if($outcome!=='success'&&$request?->path==='/api/v1/admin/privacy-audit'){
            $type=$request->query['resource_type']??null;$id=self::positiveId($request->query['resource_id']??null);
            if(is_string($type)&&in_array($type,self::resourceTypes(),true)&&$id!==null)$attempted[$type.':'.$id]=['type'=>$type,'id'=>$id];
        }
        $action = $outcome !== 'success' ? 'access.'.$outcome : ($method === 'GET' ? 'access.read' : 'access.response');
        if ($outcome === 'success' && $method === 'POST' && preg_match('#/(search|preview|validate|resolve|approval|travel-estimate)$#',$description['route'])) $action = 'access.read';
        if ($outcome === 'success' && (str_contains($description['route'],'search') || preg_match('#/(clients|booking-clients)$#',$description['route']))) $action = 'access.search';
        if ($outcome === 'success' && (str_ends_with($description['route'],'/pdf') || str_ends_with($description['route'],'/customer/appointments/:id/calendar'))) $action = 'access.download';
        $correlation = $request?->correlationId ?? bin2hex(random_bytes(16));
        $event = bin2hex(random_bytes(16));
        try {
            $data = $payload['data'] ?? [];
            $resources = $outcome === 'success' && is_array($data) ? self::resources($data,$description['entity']) : [];
            // Duplicate review is the one validation response intentionally disclosing client candidates.
            $candidates = $payload['error']['fields']['candidates'] ?? null;
            if ($code === 'possible_duplicate' && is_array($candidates) && $this->actorType !== 'anonymous') $resources = self::resources($candidates,'client');
            if ($outcome === 'success' && in_array($this->actorType,['client','staff'],true) && str_contains($description['route'],'/profile')) $resources[] = ['type'=>$this->actorType === 'client' ? 'client' : 'user','id'=>$this->actorId];
            foreach ($outcome === 'success' ? $description['targets'] : [] as $target) $resources[] = $target;
            $unique = []; foreach ($resources as $resource) $unique[$resource['type'].':'.$resource['id']] = $resource;
            $chunks = array_chunk(array_values($unique),200) ?: [[]];
            $pdo = $this->database->connection();
            // Errors must not strand the denial event inside a rejected writer's transaction.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                if ($outcome === 'success') throw new \RuntimeException('Disclosure attempted with an open transaction.');
            }
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare('INSERT INTO access_audit_events(event_group,part_number,part_count,clinic_id,actor_type,actor_id,correlation_id,action,route_key,http_method,http_status,outcome,ip_address,metadata) VALUES(:event,:part,:parts,:clinic,:actor_type,:actor_id,:correlation,:action,:route,:method,:status,:outcome,INET6_ATON(:ip),:metadata)');
                foreach ($chunks as $index=>$chunk) {
                    $metadata = $outcome === 'success' ? ['resources'=>$chunk] : ['attempted_resources'=>array_values($attempted),'error_code'=>$code,'resources'=>$chunk];
                    if ($outcome === 'success' && $requested) $metadata['requested_resources'] = array_values($requested);
                    $insert->execute(['event'=>$event,'part'=>$index+1,'parts'=>count($chunks),
                        'clinic'=>$this->actorClinic ?? $resolvedClinic,'actor_type'=>$this->actorType,'actor_id'=>$this->actorId,
                        'correlation'=>substr($correlation,0,64),'action'=>$action,'route'=>$description['route'],'method'=>$method,
                        'status'=>$status,'outcome'=>$outcome,'ip'=>filter_var($_SERVER['REMOTE_ADDR'] ?? '',FILTER_VALIDATE_IP) ?: null,
                        'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR)]);
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        } catch (Throwable) {
            // No exception text, URL, headers or payloads in the operational fallback.
            error_log('Access audit unavailable event_group='.$event.' outcome='.$outcome.' correlation_hash='.hash('sha256',$correlation));
            if ($outcome === 'success') throw new ApiException(503,'access_audit_unavailable','Secure access logging is unavailable. Please try again later.');
            // Preserve the original denial; a failed audit must never turn it into success.
        }
    }
}
