<?php
declare(strict_types=1);
namespace Wellness;

use DateTimeImmutable;
use PDO;
use Throwable;

final class Api {
  public static function handle(): void {
    header('Content-Type: application/json; charset=utf-8'); header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*')); header('Access-Control-Allow-Headers: Authorization, Content-Type');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    try { self::route($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'); }
    catch (Throwable $e) { error_log($e->getMessage()); self::reply(['error'=>['code'=>'internal_error','message'=>'An unexpected error occurred.']], 500); }
  }
  private static function route(string $method, string $path): void {
    if (!str_starts_with($path, '/api/v1')) self::reply(['error'=>['code'=>'not_found','message'=>'Versioned API route not found.']],404);
    $route = substr($path, 7) ?: '/';
    if ($method === 'GET' && $route === '/health') self::reply(['data'=>['status'=>'ok','time'=>(new DateTimeImmutable())->format(DATE_ATOM)]]);
    if ($method === 'GET' && $route === '/availability') self::availability();
    if ($method === 'POST' && $route === '/appointments') self::createAppointment();
    if ($method === 'POST' && preg_match('#^/appointments/(\d+)/(cancel|reschedule)$#',$route,$m)) { self::requireAuth(); self::reply(['data'=>['id'=>(int)$m[1],'status'=>$m[2] === 'cancel' ? 'canceled_by_client' : 'rescheduled']]); }
    if ($method === 'POST' && $route === '/waitlist') { self::requireAuth(); self::reply(['data'=>['status'=>'registered']],201); }
    if ($method === 'GET' && in_array($route,['/dashboard','/practitioners','/rooms','/services','/invoices','/reports'],true)) { self::requireAuth(); self::reply(['data'=>[]]); }
    self::reply(['error'=>['code'=>'not_found','message'=>'Route not found.']],404);
  }
  private static function availability(): void { self::reply(['data'=>['slot_increment_minutes'=>15,'availability'=>[['practitioner_id'=>1,'service_id'=>1,'room_id'=>1,'starts_at'=>'2026-09-16T09:00:00-04:00','ends_at'=>'2026-09-16T10:00:00-04:00'],['practitioner_id'=>1,'service_id'=>1,'room_id'=>1,'starts_at'=>'2026-09-16T10:15:00-04:00','ends_at'=>'2026-09-16T11:15:00-04:00']]]]); }
  private static function createAppointment(): void { $body=self::body(); foreach(['service_id','practitioner_id','starts_at'] as $key) if(empty($body[$key])) self::reply(['error'=>['code'=>'validation_error','message'=>"$key is required.",'fields'=>[$key=>'Required']]],422); self::requireAuth(); self::reply(['data'=>['id'=>1,'status'=>'confirmed','message'=>'Appointment created.']],201); }
  private static function requireAuth(): void { if (!preg_match('/^Bearer\s+.+$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) self::reply(['error'=>['code'=>'unauthorized','message'=>'Authentication required.']],401); /* Verify JWT/OAuth access token and enforce resource-level role policy here. */ }
  private static function body(): array { $data=json_decode(file_get_contents('php://input') ?: '{}',true); return is_array($data)?$data:[]; }
  private static function reply(array $payload,int $code=200): never { http_response_code($code); echo json_encode($payload,JSON_UNESCAPED_SLASHES); exit; }
}
