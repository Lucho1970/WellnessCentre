<?php
declare(strict_types=1);

namespace Wellness;

use FastRoute\Dispatcher;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Auth\EntraAuthenticator;
use Wellness\Http\ApiException;
use Wellness\Http\Request;
use Wellness\Http\Response;
use Wellness\Service\AuditLogger;
use Wellness\Service\AdminService;
use Wellness\Service\AvailabilityService;
use Wellness\Service\BookingService;
use Wellness\Service\CatalogService;
use Wellness\Service\ProfileService;
use function FastRoute\simpleDispatcher;

final class Api
{
    private EntraAuthenticator $auth;
    private CatalogService $catalog;
    private AvailabilityService $availability;
    private BookingService $bookings;
    private AdminService $admin;
    private ProfileService $profiles;

    public function __construct(private readonly Config $config,private readonly Database $database)
    {
        $audit=new AuditLogger($database);$this->auth=new EntraAuthenticator($config,$database);$this->catalog=new CatalogService($database);$this->availability=new AvailabilityService($database);$this->bookings=new BookingService($database,$audit);$this->admin=new AdminService($database,$audit);$this->profiles=new ProfileService($database,$audit);
    }

    public function handle(): never
    {
        $request=null;
        try{
            $request=Request::capture();$this->cors($request);
            if($request->method==='OPTIONS')Response::json([],204,$request->correlationId);
            $dispatcher=simpleDispatcher(function($routes):void{
                $routes->addRoute('GET','/api/v1/health','health');
                $routes->addRoute('GET','/api/v1/health/database','databaseHealth');
                $routes->addRoute('GET','/api/v1/site-config','siteConfig');
                $routes->addRoute('GET','/api/v1/locations','locations');
                $routes->addRoute('GET','/api/v1/services','services');
                $routes->addRoute('GET','/api/v1/practitioners','practitioners');
                $routes->addRoute('GET','/api/v1/availability','availability');
                $routes->addRoute('GET','/api/v1/auth/me','me');
                $routes->addRoute('GET','/api/v1/profile/avatar','profileAvatar');
                $routes->addRoute('PUT','/api/v1/profile/avatar','saveProfileAvatar');
                $routes->addRoute('DELETE','/api/v1/profile/avatar','deleteProfileAvatar');
                $routes->addRoute('PUT','/api/v1/admin/users/{id:\\d+}/avatar','adminSaveAvatar');
                $routes->addRoute('GET','/api/v1/admin/users/{id:\\d+}/avatar','adminAvatar');
                $routes->addRoute('DELETE','/api/v1/admin/users/{id:\\d+}/avatar','adminDeleteAvatar');
                $routes->addRoute('GET','/api/v1/appointments','appointments');
                $routes->addRoute('POST','/api/v1/appointments','createAppointment');
                $routes->addRoute('POST','/api/v1/admin/locations','createLocation');
                $routes->addRoute('GET','/api/v1/admin/locations','adminLocations');
                $routes->addRoute('PATCH','/api/v1/admin/locations/{id:\\d+}','updateLocation');
                $routes->addRoute('POST','/api/v1/admin/rooms','createRoom');
                $routes->addRoute('GET','/api/v1/admin/rooms','adminRooms');
                $routes->addRoute('PATCH','/api/v1/admin/rooms/{id:\\d+}','updateRoom');
                $routes->addRoute('POST','/api/v1/admin/staff','createStaff');
                $routes->addRoute('POST','/api/v1/admin/practitioners','createPractitioner');
                $routes->addRoute('GET','/api/v1/admin/practitioners','adminPractitioners');
                $routes->addRoute('POST','/api/v1/admin/practitioners/onboard','onboardPractitioner');
                $routes->addRoute('PATCH','/api/v1/admin/practitioners/{id:\\d+}','updatePractitioner');
                $routes->addRoute('POST','/api/v1/admin/services','createService');
                $routes->addRoute('POST','/api/v1/admin/availability-rules','createAvailability');
                $routes->addRoute('PATCH','/api/v1/admin/clinic','updateClinic');
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
                'profileAvatar'=>$this->profiles->avatar($this->user($request)),
                'saveProfileAvatar'=>$this->profiles->save($this->user($request),$request->body,$request->correlationId),
                'deleteProfileAvatar'=>$this->profiles->delete($this->user($request),$request->correlationId),
                'adminSaveAvatar'=>$this->profiles->save($this->user($request),$request->body,$request->correlationId,(int)$route[2]['id']),
                'adminAvatar'=>$this->profiles->avatar($this->user($request),(int)$route[2]['id']),
                'adminDeleteAvatar'=>$this->profiles->delete($this->user($request),$request->correlationId,(int)$route[2]['id']),
                'appointments'=>$this->bookings->list($this->user($request)),
                'createAppointment'=>$this->bookings->create($this->user($request),$request->body,$request->correlationId),
                'createLocation'=>$this->admin->createLocation($this->user($request),$request->body,$request->correlationId),
                'adminLocations'=>$this->admin->locations($this->user($request)),
                'updateLocation'=>$this->admin->updateLocation($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createRoom'=>$this->admin->createRoom($this->user($request),$request->body,$request->correlationId),
                'adminRooms'=>$this->admin->rooms($this->user($request)),
                'updateRoom'=>$this->admin->updateRoom($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createStaff'=>$this->admin->createStaff($this->user($request),$request->body,$request->correlationId),
                'createPractitioner'=>$this->admin->createPractitioner($this->user($request),$request->body,$request->correlationId),
                'adminPractitioners'=>$this->admin->practitioners($this->user($request)),
                'onboardPractitioner'=>$this->admin->onboardPractitioner($this->user($request),$request->body,$request->correlationId),
                'updatePractitioner'=>$this->admin->updatePractitioner($this->user($request),(int)$route[2]['id'],$request->body,$request->correlationId),
                'createService'=>$this->admin->createService($this->user($request),$request->body,$request->correlationId),
                'createAvailability'=>$this->admin->createAvailability($this->user($request),$request->body,$request->correlationId),
                'updateClinic'=>$this->admin->updateClinic($this->user($request),$request->body,$request->correlationId),
                default=>throw new ApiException(500,'route_handler_missing','Route handler is not configured.'),
            };
            $created=str_starts_with((string)$route[1],'create');
            Response::json(['data'=>$data],$created?201:200,$request->correlationId);
        }catch(ApiException $e){Response::json(['error'=>array_filter(['code'=>$e->errorCode,'message'=>$e->getMessage(),'fields'=>$e->fields?:null,'correlation_id'=>$request?->correlationId])],$e->status,$request?->correlationId);}
        catch(Throwable $e){error_log($e->__toString());$message=$this->config->debug?$e->getMessage():'An unexpected error occurred.';Response::json(['error'=>['code'=>'internal_error','message'=>$message,'correlation_id'=>$request?->correlationId]],500,$request?->correlationId);}
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
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Correlation-ID, Idempotency-Key');header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    }
}
