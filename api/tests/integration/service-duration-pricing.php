<?php
declare(strict_types=1);
// Synthetic integration test against a disposable localhost database. No .env is loaded.
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AdminService;
use Wellness\Service\AuditLogger;
use Wellness\Service\CatalogService;
$port=(int)(getenv('ONBOARDING_TEST_PORT')?:13317);
$root=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('ONBOARDING_TEST_USER')?:'root',getenv('ONBOARDING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='wellness_service_test_'.bin2hex(random_bytes(6));
$root->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$root->exec("USE `$name`");
$schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$schema=preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',$schema);$root->exec($schema);
$root->exec("INSERT INTO clinics(id,name)VALUES(1,'Synthetic clinic'),(2,'Other clinic');INSERT INTO users(id,clinic_id,email,display_name,user_type,status)VALUES(1,1,'admin@example.test','Admin','staff','active');INSERT INTO service_categories(id,clinic_id,name)VALUES(10,1,'Massage Therapy'),(20,2,'Other Clinic Category')");
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','staff','staff-api','scope',3600);
$database=new Database($config);$service=new AdminService($database,new AuditLogger($database));$actor=new AuthContext(1,1,'admin','admin@example.test','Admin','staff',['super_admin']);
$base=['category_id'=>10,'slug'=>'massage-therapy','name'=>'Massage','name_fr'=>'Massage','public_summary'=>'Synthetic public summary','public_summary_fr'=>'Résumé public synthétique','description'=>'Test only','description_fr'=>'Test français','preparation_instructions'=>'Arrive comfortably dressed.','preparation_instructions_fr'=>'Portez des vêtements confortables.','published'=>true,'display_order'=>20,'duration_options'=>[['minutes'=>60,'price_cents'=>11000],['minutes'=>90,'price_cents'=>15500],['minutes'=>120,'price_cents'=>20000]],'lead_time_minutes'=>0,'booking_horizon_days'=>365,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'requires_room'=>false,'recurrence_allowed'=>false];
$id=$service->createService($actor,$base,'duration-test')['id'];$rows=$service->services($actor);
if(count($rows)!==1||$rows[0]['duration_options']!==$base['duration_options']||(int)$rows[0]['price_cents']!==11000||(int)$rows[0]['category_id']!==10||$rows[0]['category_name']!=='Massage Therapy'||$rows[0]['slug']!=='massage-therapy'||(int)$rows[0]['published']!==1)throw new RuntimeException('Created service publication, category, or duration prices were not returned accurately');
$public=(new CatalogService($database))->publicServices();
if(count($public)!==1||$public[0]['slug']!=='massage-therapy'||$public[0]['name_fr']!=='Massage'||count($public[0]['durations'])!==3||array_key_exists('id',$public[0]))throw new RuntimeException('Public service projection was incomplete or exposed an internal service ID');
try{$service->createService($actor,array_replace($base,['name'=>'Invalid category','category_id'=>20]),'duration-test');throw new RuntimeException('A service accepted another clinic category');}catch(ApiException $error){if($error->status!==422||$error->errorCode!=='validation_error')throw $error;}
$updated=array_replace($base,['category_id'=>null,'duration_options'=>[['minutes'=>60,'price_cents'=>11500],['minutes'=>120,'price_cents'=>20500]],'active'=>true]);
$service->updateService($actor,$id,$updated,'duration-test');$rows=$service->services($actor);
if($rows[0]['duration_options']!==$updated['duration_options']||(int)$rows[0]['price_cents']!==11500||$rows[0]['category_id']!==null||$rows[0]['category_name']!==null)throw new RuntimeException('Updated category or duration prices were not returned accurately');
$inactive=$root->query("SELECT duration_minutes,price_cents,active FROM service_duration_options WHERE service_id=$id ORDER BY duration_minutes")->fetchAll(PDO::FETCH_ASSOC);
if(count($inactive)!==3||(int)$inactive[1]['duration_minutes']!==90||(int)$inactive[1]['active']!==0||(int)$inactive[1]['price_cents']!==15500)throw new RuntimeException('Removed duration history was not retained as inactive');
echo "10 service publication, category, and duration database checks passed on ".$root->getAttribute(PDO::ATTR_SERVER_VERSION)."; scratch database: $name\n";
