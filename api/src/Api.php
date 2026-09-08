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
use function FastRoute\simpleDispatcher;

final class Api
{
    private EntraAuthenticator $auth;
    private CatalogService $catalog;
    private AvailabilityService $availability;
    private BookingService $bookings;
    private AdminService $admin;

    public function __construct(private readonly Config $config,private readonly Database $database)
    {
        $audit=new AuditLogger($database);$this->auth=new EntraAuthenticator($config,$database);$this->catalog=new CatalogService($database);$this->availability=new AvailabilityService($database);$this->bookings=new BookingService($database,$audit);$this->admin=new AdminService($database,$audit);
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
                $routes->addRoute('GET','/api/v1/locations','locations');
                $routes->addRoute('GET','/api/v1/services','services');
                $routes->addRoute('GET','/api/v1/practitioners','practitioners');
                $routes->addRoute('GET','/api/v1/availability','availability');
                $routes->addRoute('GET','/api/v1/auth/me','me');
                $routes->addRoute('GET','/api/v1/appointments','appointments');
                $routes->addRoute('POST','/api/v1/appointments','createAppointment');
                $routes->addRoute('POST','/api/v1/admin/locations','createLocation');
                $routes->addRoute('POST','/api/v1/admin/rooms','createRoom');
                $routes->addRoute('POST','/api/v1/admin/staff','createStaff');
                $routes->addRoute('POST','/api/v1/admin/practitioners','createPractitioner');
                $routes->addRoute('POST','/api/v1/admin/services','createService');
                $routes->addRoute('POST','/api/v1/admin/availability-rules','createAvailability');
            });
            $route=$dispatcher->dispatch($request->method,$request->path);
            if($route[0]===Dispatcher::NOT_FOUND)throw new ApiException(404,'not_found','Route not found.');
            if($route[0]===Dispatcher::METHOD_NOT_ALLOWED)throw new ApiException(405,'method_not_allowed','Method not allowed.');
            $data=match($route[1]){
                'health'=>['status'=>'ok','time'=>gmdate(DATE_ATOM),'environment'=>$this->config->environment],
                'databaseHealth'=>$this->databaseHealth(),
                'locations'=>$this->catalog->locations(),
                'services'=>$this->catalog->services(isset($request->query['practitioner_id'])?(int)$request->query['practitioner_id']:null),
                'practitioners'=>$this->catalog->practitioners(isset($request->query['service_id'])?(int)$request->query['service_id']:null),
                'availability'=>$this->availability->search($request->query),
                'me'=>$this->me($this->user($request)),
                'appointments'=>$this->bookings->list($this->user($request)),
                'createAppointment'=>$this->bookings->create($this->user($request),$request->body,$request->correlationId),
                'createLocation'=>$this->admin->createLocation($this->user($request),$request->body,$request->correlationId),
                'createRoom'=>$this->admin->createRoom($this->user($request),$request->body,$request->correlationId),
                'createStaff'=>$this->admin->createStaff($this->user($request),$request->body,$request->correlationId),
                'createPractitioner'=>$this->admin->createPractitioner($this->user($request),$request->body,$request->correlationId),
                'createService'=>$this->admin->createService($this->user($request),$request->body,$request->correlationId),
                'createAvailability'=>$this->admin->createAvailability($this->user($request),$request->body,$request->correlationId),
                default=>throw new ApiException(500,'route_handler_missing','Route handler is not configured.'),
            };
            $created=str_starts_with((string)$route[1],'create');
            Response::json(['data'=>$data],$created?201:200,$request->correlationId);
        }catch(ApiException $e){Response::json(['error'=>array_filter(['code'=>$e->errorCode,'message'=>$e->getMessage(),'fields'=>$e->fields?:null,'correlation_id'=>$request?->correlationId])],$e->status,$request?->correlationId);}
        catch(Throwable $e){error_log($e->__toString());$message=$this->config->debug?$e->getMessage():'An unexpected error occurred.';Response::json(['error'=>['code'=>'internal_error','message'=>$message,'correlation_id'=>$request?->correlationId]],500,$request?->correlationId);}
    }

    private function databaseHealth(): array
    {
        $pdo=$this->database->connection();$version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();$tables=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();return ['status'=>'ok','driver'=>'mysql','server_version'=>$version,'database'=>$this->config->dbName,'table_count'=>$tables];
    }

    private function user(Request $request): AuthContext{return $this->auth->authenticate($request->bearerToken());}
    private function me(AuthContext $user): array{return ['id'=>$user->userId,'clinic_id'=>$user->clinicId,'email'=>$user->email,'display_name'=>$user->displayName,'user_type'=>$user->userType,'roles'=>$user->roles];}

    private function cors(Request $request): void
    {
        $origin=$request->headers['origin']??'';
        if($origin!==''&&in_array($origin,$this->config->allowedOrigins,true)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');header('Access-Control-Allow-Credentials: true');}
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Correlation-ID, Idempotency-Key');header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    }
}
