<?php
declare(strict_types=1);

namespace Wellness;

use FastRoute\Dispatcher;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Auth\EntraAuthenticator;
use Wellness\Auth\CustomerAuthenticator;
use Wellness\Http\ApiException;
use Wellness\Http\Request;
use Wellness\Http\Response;
use Wellness\Service\AuditLogger;
use Wellness\Service\AdminService;
use Wellness\Service\AvailabilityService;
use Wellness\Service\BookingService;
use Wellness\Service\CatalogService;
use Wellness\Service\ProfileService;
use Wellness\Service\ClientService;
use Wellness\Service\CustomerOnboarding;
use function FastRoute\simpleDispatcher;

final class Api
{
    private EntraAuthenticator $auth;
    private CatalogService $catalog;
    private AvailabilityService $availability;
    private BookingService $bookings;
    private AdminService $admin;
    private ProfileService $profiles;
    private ClientService $clients;

    public function __construct(private readonly Config $config,private readonly Database $database)
    {
        $audit=new AuditLogger($database);$this->auth=new EntraAuthenticator($config,$database);$this->catalog=new CatalogService($database);$this->availability=new AvailabilityService($database);$this->bookings=new BookingService($database,$audit);$this->admin=new AdminService($database,$audit);$this->profiles=new ProfileService($database,$audit);$this->clients=new ClientService($database,$audit);
    }

    public function handle(): never
    {
        $request=null;
        try{
            $request=Request::capture();$this->cors($request);
            if($request->method==='OPTIONS')Response::json([],204,$request->correlationId);
            if (str_starts_with($request->path, '/api/v1/customer/') && $request->path !== '/api/v1/customer/auth/me') {
                Response::json(['data' => $this->customerRoute($request)], 200, $request->correlationId);
            }
            $dispatcher=simpleDispatcher(function($routes):void{
                $routes->addRoute('GET','/api/v1/health','health');
                $routes->addRoute('GET','/api/v1/health/database','databaseHealth');
                $routes->addRoute('GET','/api/v1/site-config','siteConfig');
                $routes->addRoute('GET','/api/v1/locations','locations');
                $routes->addRoute('GET','/api/v1/services','services');
                $routes->addRoute('GET','/api/v1/practitioners','practitioners');
                $routes->addRoute('GET','/api/v1/availability','availability');
                $routes->addRoute('GET','/api/v1/auth/me','me');
                $routes->addRoute('GET','/api/v1/customer/auth/me','customerMe');
                $routes->addRoute('GET','/api/v1/profile/avatar','profileAvatar');
                $routes->addRoute('PUT','/api/v1/profile/avatar','saveProfileAvatar');
                $routes->addRoute('DELETE','/api/v1/profile/avatar','deleteProfileAvatar');
                $routes->addRoute('PUT','/api/v1/admin/users/{id:\\d+}/avatar','adminSaveAvatar');
                $routes->addRoute('GET','/api/v1/admin/users/{id:\\d+}/avatar','adminAvatar');
                $routes->addRoute('DELETE','/api/v1/admin/users/{id:\\d+}/avatar','adminDeleteAvatar');
                $routes->addRoute('GET','/api/v1/appointments','appointments');
                $routes->addRoute('POST','/api/v1/appointments','createAppointment');
                $routes->addRoute('GET','/api/v1/booking-options','bookingOptions');
                $routes->addRoute('GET','/api/v1/clients','clients');
                $routes->addRoute('POST','/api/v1/clients','createClient');
                $routes->addRoute('GET','/api/v1/clients/{id:\\d+}','client');
                $routes->addRoute('PATCH','/api/v1/clients/{id:\\d+}','updateClient');
                $routes->addRoute('GET','/api/v1/clients/{id:\\d+}/invitations','clientInvitations');
                $routes->addRoute('POST','/api/v1/clients/{id:\\d+}/invitations','issueClientInvitation');
                $routes->addRoute('POST','/api/v1/clients/{id:\\d+}/invitations/{invitation:\\d+}','reviewClientInvitation');
                $routes->addRoute('POST','/api/v1/admin/locations','createLocation');
                $routes->addRoute('GET','/api/v1/admin/locations','adminLocations');
                $routes->addRoute('PATCH','/api/v1/admin/locations/{id:\\d+}','updateLocation');
                $routes->addRoute('POST','/api/v1/admin/rooms','createRoom');
                $routes->addRoute('GET','/api/v1/admin/rooms','adminRooms');
                $routes->addRoute('PATCH','/api/v1/admin/rooms/{id:\\d+}','updateRoom');
                $routes->addRoute('GET','/api/v1/admin/room-capabilities','roomCapabilities');
                $routes->addRoute('POST','/api/v1/admin/room-capabilities','createRoomCapability');
                $routes->addRoute('PUT','/api/v1/admin/room-capability-assignments','updateRoomCapabilityAssignments');
                $routes->addRoute('GET','/api/v1/admin/room-practitioner-restrictions','roomPractitionerRestrictions');
                $routes->addRoute('PUT','/api/v1/admin/rooms/{id:\\d+}/practitioners','updateRoomPractitioners');
                $routes->addRoute('POST','/api/v1/admin/staff','createStaff');
                $routes->addRoute('GET','/api/v1/admin/staff','adminStaff');
                $routes->addRoute('PATCH','/api/v1/admin/staff/{id:\\d+}','updateStaff');
                $routes->addRoute('POST','/api/v1/admin/practitioners','createPractitioner');
                $routes->addRoute('GET','/api/v1/admin/practitioners','adminPractitioners');
                $routes->addRoute('POST','/api/v1/admin/practitioners/onboard','onboardPractitioner');
                $routes->addRoute('PATCH','/api/v1/admin/practitioners/{id:\\d+}','updatePractitioner');
                $routes->addRoute('POST','/api/v1/admin/services','createService');
                $routes->addRoute('GET','/api/v1/admin/services','adminServices');
                $routes->addRoute('PATCH','/api/v1/admin/services/{id:\\d+}','updateService');
                $routes->addRoute('GET','/api/v1/admin/service-assignments','serviceAssignments');
                $routes->addRoute('PUT','/api/v1/admin/services/{id:\\d+}/assignments','updateServiceAssignments');
                $routes->addRoute('POST','/api/v1/admin/availability-rules','createAvailability');
                $routes->addRoute('GET','/api/v1/admin/availability-rules','availabilityRules');
                $routes->addRoute('DELETE','/api/v1/admin/availability-rules/{id:\\d+}','deleteAvailabilityRule');
                $routes->addRoute('GET','/api/v1/admin/schedule-exceptions','scheduleExceptions');
                $routes->addRoute('POST','/api/v1/admin/availability-overrides','createAvailabilityOverride');
                $routes->addRoute('POST','/api/v1/admin/time-off','createTimeOff');
                $routes->addRoute('DELETE','/api/v1/admin/availability-overrides/{id:\\d+}','deleteAvailabilityOverride');
                $routes->addRoute('DELETE','/api/v1/admin/time-off/{id:\\d+}','deleteTimeOff');
                $routes->addRoute('PATCH','/api/v1/admin/clinic','updateClinic');
                $routes->addRoute('GET','/api/v1/admin/catalogue-settings','catalogueSettings');
                $routes->addRoute('POST','/api/v1/admin/service-categories','createServiceCategory');
                $routes->addRoute('POST','/api/v1/admin/taxes','createTax');
                $routes->addRoute('PATCH','/api/v1/admin/booking-settings','updateBookingSettings');
            });
            $route=$dispatcher->dispatch($request->method,$request->path);
            if($route[0]===Dispatcher::NOT_FOUND)throw new ApiException(404,'not_found','Route not found.');
            if($route[0]===Dispatcher::METHOD_NOT_ALLOWED)throw new ApiException(405,'method_not_allowed','Method not allowed.');
            $data=match($route[1]){
                'health'=>['status'=>'ok','time'=>gmdate(DATE_ATOM),'environment'=>$this->config->environment],
                'databaseHealth'=>$this->databaseHealth(),
                'siteConfig'=>$this->catalog->siteConfig(),
                'locations'=>$this->catalog->locations(),
                'services'=>$this->catalog->services(isset($request->query['practitioner_id'])?(int)$request->query['practitioner_id']:null),
                'practitioners'=>$this->catalog->practitioners(isset($request->query['service_id'])?(int)$request->query['service_id']:null),
                'availability'=>$this->availability->search($request->query),
                'me'=>$this->me($this->user($request)),
                'customerMe'=>$this->customerMe($request),
                'profileAvatar'=>$this->profiles->avatar($this->user($request)),
                'saveProfileAvatar'=>$this->profiles->save($this->user($request),$request->body,$request->correlationId),
                'deleteProfileAvatar'=>$this->profiles->delete($this->user($request),$request->correlationId),
                'adminSaveAvatar'=>$this->profiles->save($this->user($request),$request->body,$request->correlationId,(int)$route[2]['id']),
                'adminAvatar'=>$this->profiles->avatar($this->user($request),(int)$route[2]['id']),
                'adminDeleteAvatar'=>$this->profiles->delete($this->user($request),$request->correlationId,(int)$route[2]['id']),
                'appointments'=>$this->bookings->list($this->user($request),$request->query),
                'bookingOptions'=>$this->bookings->options($this->user($request)),
                'createAppointment'=>$this->bookings->create($this->user($request),$request->body,$request->correlationId),
                'clients'=>$this->clients->search($this->user($request),$request->query),
                'client'=>$this->clients->get($this->user($request),(int)$route[2]['id'],$request->correlationId),
                'createClient'=>$this->clients->save($this->user($request),$request->body,$request->correlationId),
                'updateClient'=>$this->clients->save($this->user($request),$request->body,$request->correlationId,(int)$route[2]['id']),
                'clientInvitations'=>$this->onboarding()->invitations($this->user($request),(int)$route[2]['id']),
                'issueClientInvitation'=>$this->onboarding()->invite($this->user($request),(int)$route[2]['id'],$request->correlationId),
                'reviewClientInvitation'=>$this->onboarding()->review($this->user($request),(int)$route[2]['id'],(int)$route[2]['invitation'],$request->body,$request->correlationId),
                'createLocation'=>$this->admin->createLocation($this->user($request),$request->body,$request->correlationId),
                'adminLocations'=>$this->admin->locations($this->user($request)),
                'updateLocation'=>$this->admin->updateLocation($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createRoom'=>$this->admin->createRoom($this->user($request),$request->body,$request->correlationId),
                'adminRooms'=>$this->admin->rooms($this->user($request)),
                'updateRoom'=>$this->admin->updateRoom($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'roomCapabilities'=>$this->admin->roomCapabilities($this->user($request)),
                'createRoomCapability'=>$this->admin->createRoomCapability($this->user($request),$request->body,$request->correlationId),
                'updateRoomCapabilityAssignments'=>$this->admin->updateRoomCapabilityAssignments($this->user($request),$request->body,$request->correlationId),
                'roomPractitionerRestrictions'=>$this->admin->roomPractitionerRestrictions($this->user($request)),
                'updateRoomPractitioners'=>$this->admin->updateRoomPractitioners($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createStaff'=>$this->admin->createStaff($this->user($request),$request->body,$request->correlationId),
                'adminStaff'=>$this->admin->staff($this->user($request)),
                'updateStaff'=>$this->admin->updateStaff($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createPractitioner'=>$this->admin->createPractitioner($this->user($request),$request->body,$request->correlationId),
                'adminPractitioners'=>$this->admin->practitioners($this->user($request)),
                'onboardPractitioner'=>$this->admin->onboardPractitioner($this->user($request),$request->body,$request->correlationId),
                'updatePractitioner'=>$this->admin->updatePractitioner($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createService'=>$this->admin->createService($this->user($request),$request->body,$request->correlationId),
                'adminServices'=>$this->admin->services($this->user($request)),
                'updateService'=>$this->admin->updateService($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'serviceAssignments'=>$this->admin->serviceAssignments($this->user($request)),
                'updateServiceAssignments'=>$this->admin->updateServiceAssignments($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createAvailability'=>$this->admin->createAvailability($this->user($request),$request->body,$request->correlationId),
                'availabilityRules'=>$this->admin->availabilityRules($this->user($request)),
                'deleteAvailabilityRule'=>$this->admin->deleteAvailabilityRule($this->user($request),(int)$route[2]['id'],$request->correlationId),
                'scheduleExceptions'=>$this->admin->scheduleExceptions($this->user($request)),
                'createAvailabilityOverride'=>$this->admin->createAvailabilityOverride($this->user($request),$request->body,$request->correlationId),
                'createTimeOff'=>$this->admin->createTimeOff($this->user($request),$request->body,$request->correlationId),
                'deleteAvailabilityOverride'=>$this->admin->deleteScheduleException($this->user($request),'availability_overrides',(int)$route[2]['id'],$request->correlationId),
                'deleteTimeOff'=>$this->admin->deleteScheduleException($this->user($request),'time_off',(int)$route[2]['id'],$request->correlationId),
                'updateClinic'=>$this->admin->updateClinic($this->user($request),$request->body,$request->correlationId),
                'catalogueSettings'=>$this->admin->catalogueSettings($this->user($request)),
                'createServiceCategory'=>$this->admin->createServiceCategory($this->user($request),$request->body,$request->correlationId),
                'createTax'=>$this->admin->createTax($this->user($request),$request->body,$request->correlationId),
                'updateBookingSettings'=>$this->admin->updateBookingSettings($this->user($request),$request->body,$request->correlationId),
                default=>throw new ApiException(500,'route_handler_missing','Route handler is not configured.'),
            };
            $created=str_starts_with((string)$route[1],'create');
            Response::json(['data'=>$data],$created?201:200,$request->correlationId);
        }catch(ApiException $e){Response::json(['error'=>array_filter(['code'=>$e->errorCode,'message'=>$e->getMessage(),'fields'=>$e->fields?:null,'correlation_id'=>$request?->correlationId])],$e->status,$request?->correlationId);}
        catch(Throwable $e){
            // Exception messages/traces can contain SQL contact values or authentication arguments.
            error_log('API failure '.get_class($e).' correlation_id='.($request?->correlationId ?? 'unavailable'));
            Response::json(['error'=>['code'=>'internal_error','message'=>'An unexpected error occurred.','correlation_id'=>$request?->correlationId]],500,$request?->correlationId);
        }
    }

    private function customerMe(Request $request): array
    {
        header('Cache-Control: no-store');
        if ($this->config->customerOnboardingEnabled) {
            $service = $this->onboarding();
            $session = $service->session((new CustomerAuthenticator($this->config))->claims($request->bearerToken()), $request->headers['x-customer-session'] ?? null);
            $identity = $session['identity_id']; unset($session['identity_id']);
            return $service->status($identity) + ['session' => $session];
        }
        return (new CustomerAuthenticator($this->config))->authenticate($request->bearerToken());
    }

    private function onboarding(): CustomerOnboarding
    {
        if (!$this->config->customerOnboardingEnabled) throw new ApiException(503, 'onboarding_unavailable', 'Client onboarding is not enabled.');
        return new CustomerOnboarding($this->database->connection(), $this->config);
    }

    private function customerRoute(Request $r): array
    {
        $route = $r->method . ' ' . substr($r->path, strlen('/api/v1/customer/'));
        if ($route === 'GET auth/options') return ['onboarding_enabled' => $this->config->customerOnboardingEnabled];
        $service = $this->onboarding();
        if ($route === 'POST auth/challenge') return $service->challenge($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $token = $r->headers['x-customer-session'] ?? null;
        if ($route === 'POST auth/logout') return $service->logout($token, $r->correlationId);
        $auth = new CustomerAuthenticator($this->config);
        $claims = $auth->claims($r->bearerToken());
        if ($route === 'POST auth/session') {
            if (!is_string($r->body['id_token'] ?? null)) throw new ApiException(422, 'invalid_proof', 'A fresh sign-in proof is required.');
            return $service->startSession($claims, $auth->claims($r->body['id_token'], true), $r->correlationId);
        }
        $session = $service->session($claims, $token, $route === 'POST auth/activity');
        $identity = $session['identity_id'];
        unset($session['identity_id']);
        return match ($route) {
            'POST auth/activity' => ['session' => $session],
            'POST register' => $service->register($identity, $r->body, $r->correlationId),
            'POST invitations/accept' => $service->accept($identity, $r->body, $r->correlationId),
            'GET profile' => $service->profile($identity, $r->correlationId),
            'PATCH profile' => $service->saveProfile($identity, $r->body, $r->correlationId),
            'GET appointments' => $service->appointments($identity, $r->correlationId),
            default => throw new ApiException(404, 'not_found', 'Route not found.'),
        };
    }

    private function databaseHealth(): array
    {
        $this->database->connection()->query('SELECT 1')->fetchColumn();
        return ['status'=>'ok'];
    }

    private function user(Request $request): AuthContext{return $this->auth->authenticate($request->bearerToken());}
    private function me(AuthContext $user): array{return ['id'=>$user->userId,'clinic_id'=>$user->clinicId,'email'=>$user->email,'display_name'=>$user->displayName,'user_type'=>$user->userType,'roles'=>$user->roles];}

    private function cors(Request $request): void
    {
        $origin=$request->headers['origin']??'';
        if($origin!==''&&in_array($origin,$this->config->allowedOrigins,true)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');header('Access-Control-Allow-Credentials: true');}
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Correlation-ID, Idempotency-Key, X-Customer-Session');header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    }
}
