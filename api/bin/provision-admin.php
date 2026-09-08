<?php
declare(strict_types=1);

use Wellness\Config;
use Wellness\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
$root=dirname(__DIR__);Config::loadEnvFile($root.'/.env');
$options=getopt('', ['tenant:','oid:','email:','name:','clinic:','location:','timezone::']);
foreach(['tenant','oid','email','name','clinic','location'] as $required)if(empty($options[$required])){fwrite(STDERR,"Missing --{$required}\n");exit(2);}
$pdo=(new Database(Config::fromEnvironment()))->connection();
try{
    $pdo->beginTransaction();
    $statement=$pdo->prepare('SELECT id FROM clinics WHERE name=:name');$statement->execute(['name'=>$options['clinic']]);$clinicId=$statement->fetchColumn();
    if(!$clinicId){$statement=$pdo->prepare('INSERT INTO clinics(name,status) VALUES(:name,\'active\')');$statement->execute(['name'=>$options['clinic']]);$clinicId=$pdo->lastInsertId();}
    $statement=$pdo->prepare('SELECT id FROM locations WHERE clinic_id=:clinic AND name=:name');$statement->execute(['clinic'=>$clinicId,'name'=>$options['location']]);$locationId=$statement->fetchColumn();
    if(!$locationId){$statement=$pdo->prepare('INSERT INTO locations(clinic_id,name,timezone) VALUES(:clinic,:name,:timezone)');$statement->execute(['clinic'=>$clinicId,'name'=>$options['location'],'timezone'=>$options['timezone']??'America/Toronto']);$locationId=$pdo->lastInsertId();}
    $statement=$pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(:clinic,:email,:name,'staff','active')");$statement->execute(['clinic'=>$clinicId,'email'=>strtolower($options['email']),'name'=>$options['name']]);$userId=$pdo->lastInsertId();
    $statement=$pdo->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject,email_at_link_time) VALUES(:user,'microsoft',:tenant,:oid,:email)");$statement->execute(['user'=>$userId,'tenant'=>$options['tenant'],'oid'=>$options['oid'],'email'=>strtolower($options['email'])]);
    $statement=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,location_id) SELECT :user,id,NULL FROM roles WHERE code='super_admin'");$statement->execute(['user'=>$userId]);
    $statement=$pdo->prepare('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(:user,1)');$statement->execute(['user'=>$userId]);
    $pdo->commit();fwrite(STDOUT,"Provisioned super admin user {$userId} for clinic {$clinicId}.\n");
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
