<?php
declare(strict_types=1);
require __DIR__.'/recurring-bookings.php';
use Wellness\Service\{PublicSocialLinks,PractitionerPublicProfileService,CatalogService};
use Wellness\Auth\AuthContext;
use Wellness\Service\AuditLogger;
use Wellness\Http\ApiException;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/046_practitioner_social_links.sql'));
$checks=0;$assert=function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$pdo->exec("INSERT INTO public_team_profiles(user_id,clinic_id,slug,section,public_name,booking_name,public_title,published,show_booking_action) VALUES(2,1,'test-person','practitioner','Test person','Test','Practitioner',1,1)");
$actor=new AuthContext(2,1,'','','','staff',['practitioner']);
$profiles=new PractitionerPublicProfileService($database,new AuditLogger($database));
$body=['public_name'=>'Test person','booking_name'=>'Test','social_links'=>[['label'=>'Instagram','url'=>'https://instagram.com/synthetic'],['label'=>'Other network','url'=>'https://example.test/profile']]];
$assert($profiles->get($actor)['social_links']===[],'Migration leaves profiles private by default');
$assert($profiles->update($actor,$body,'social-test')['social_links']===$body['social_links'],'Self update persists multiple social links');
$catalogue=new CatalogService($database,1);
$assert($catalogue->publicPractitioners()[0]['social_links']===$body['social_links'],'Published public projection contains opted-in links');
$assert((new CatalogService($database,2))->publicPractitioners()===[],'Other clinic cannot read profile');
$wrongClinic=new AuthContext(2,2,'','','','staff',['practitioner']);
try{$profiles->update($wrongClinic,$body,'test');throw new RuntimeException('Cross-clinic write accepted');}catch(ApiException $e){$assert($e->errorCode==='profile_not_found','Self write remains clinic scoped');}
$old=$body;unset($old['social_links']);
$assert($profiles->update($actor,$old,'social-test')['social_links']===$body['social_links'],'Older clients preserve existing social links');
$pdo->exec('UPDATE public_team_profiles SET published=0 WHERE user_id=2');
$assert($catalogue->publicPractitioners()===[],'Unpublished profile links never leak');
$pdo->exec('UPDATE public_team_profiles SET published=1 WHERE user_id=2');
$pdo->exec('UPDATE services SET published=1 WHERE id=4');
$pdo->exec("SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',ONLY_FULL_GROUP_BY')");
$pdo->exec('UPDATE practitioner_services SET price_override_cents=7500 WHERE practitioner_id=3 AND service_id=4');
$assert($catalogue->publicServices()[0]['durations'][0]['price_cents']===7500,'From price uses practitioner override');
$pdo->exec('UPDATE practitioner_services SET price_override_cents=12500 WHERE practitioner_id=3 AND service_id=4');
$assert($catalogue->publicServices()[0]['durations'][0]['price_cents']===12500,'From price does not underquote a higher practitioner override');
$pdo->exec('UPDATE public_team_profiles SET published=0 WHERE user_id=2');
$assert($catalogue->publicServices()[0]['durations'][0]['price_cents']===10000,'No published practitioner falls back to duration price');
$pdo->exec('UPDATE public_team_profiles SET published=1 WHERE user_id=2');
$pdo->exec('UPDATE practitioner_services SET price_override_cents=0 WHERE practitioner_id=3 AND service_id=4');
$assert($catalogue->publicServices()[0]['durations'][0]['price_cents']===0,'Explicit zero practitioner price is retained');
$pdo->exec('UPDATE practitioner_services SET price_override_cents=NULL WHERE practitioner_id=3 AND service_id=4');
$pdo->exec('INSERT INTO service_duration_options(service_id,duration_minutes,price_cents) VALUES(4,90,15000)');
$assert(array_column($catalogue->publicServices()[0]['durations'],'price_cents')===[10000,15000],'Each duration keeps its own fallback price');
$assert((new CatalogService($database,2))->publicServices()===[],'Empty clinic returns no prices or services');
foreach ([[['label'=>'X','url'=>'javascript:alert(1)']],[['label'=>'X','url'=>'http://example.test']],[['label'=>'X','url'=>'https://user:pass@example.test']],[['label'=>'','url'=>'https://example.test']],array_fill(0,21,['label'=>'X','url'=>'https://example.test']),[['label'=>'X','url'=>'https://example.test'],['label'=>'Y','url'=>'https://example.test']]] as $invalid) {
    try{PublicSocialLinks::validate($invalid);throw new RuntimeException('Invalid social accepted');}catch(ApiException){$assert(true,'Unsafe social rejected');}
}
$body['social_links']=[];
$assert($profiles->update($actor,$body,'social-test')['social_links']===[],'Practitioner can remove all public social links');
echo "Practitioner social links SQL: $checks checks passed.\n";
