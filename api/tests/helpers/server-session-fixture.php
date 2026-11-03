<?php
declare(strict_types=1);
require_once __DIR__.'/browser-session.php';
use Wellness\{Config,SessionConnection};
use Wellness\Service\BrowserSessionPolicy;

final class ServerSessionFixture
{
    public static function ids(string $realm):array
    {
        $n=match($realm){'practitioner'=>2,'client'=>3,default=>1};
        return ['11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-22222222222'.$n,'33333333-3333-4333-8333-33333333333'.$n];
    }
    public static function oid(string $profile):string{return 'aaaaaaaa-aaaa-4aaa-8aaa-'.substr(hash('sha256',$profile),0,12);}
    public static function policy(string $realm):BrowserSessionPolicy
    {
        [$tenant,$spa,$api]=self::ids($realm);
        return new BrowserSessionPolicy($realm,$realm==='central'?'admin.session.test':'clinic-a.session.test',$realm==='central'?null:1,$tenant,$api,$spa,match($realm){'client'=>'access_as_client','practitioner'=>'access_as_staff',default=>'access_as_user'});
    }
    public static function claims(string $realm,string $nonce='',bool $proof=false,?string $profile=null):array
    {
        $p=self::policy($realm);$profile ??= match($realm){'client'=>'client','practitioner'=>'microsoft',default=>'workforce'};$now=time();
        $claims=['iss'=>$p->issuer(),'tid'=>$p->tenant,'aud'=>$proof?$p->spaAudience:$p->apiAudience,'ver'=>'2.0','sub'=>($proof?'spa-':'api-').$profile,'oid'=>self::oid($profile),'iat'=>$now-5,'nbf'=>$now-5,'exp'=>$now+86400];
        if($proof)return $claims+['nonce'=>$nonce,'auth_time'=>$now];
        return $claims+['azp'=>$p->spaAudience,'scp'=>$p->scope,'roles'=>['Wellness.SuperAdmin','Wellness.Practitioner']];
    }
    public static function config(int $port,string $name,bool $enabled=true):Config
    {
        [$tenant,$spa,$api]=self::ids('workforce');[, $staffSpa,$staffApi]=self::ids('practitioner');[, $clientSpa,$clientApi]=self::ids('client');
        return new Config('test',false,'synthetic-test-key',['https://clinic-a.session.test','https://admin.session.test'],'127.0.0.1',$port,$name,'root','',$tenant,$api,'access_as_user',300,
            customerTenantId:$tenant,customerSubdomain:'testclients',customerApiClientId:$clientApi,customerSpaClientId:$clientSpa,customerOnboardingEnabled:true,customerClinicId:1,
            clinicHostMap:['clinic-a.session.test'=>1,'clinic-b.session.test'=>2],staffInvitationsEnabled:true,staffExternalTenantId:$tenant,staffExternalSubdomain:'teststaff',staffExternalApiClientId:$staffApi,staffExternalSpaClientId:$staffSpa,
            clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'admin.session.test',externalStaffAuthEnabled:true,practitionerPilotAccessEnabled:true,practitionerApplicationsEnabled:true,practitionerApplicationActivationEnabled:true,
            browserSessionClinicIds:$enabled?[1,2]:[],browserSessionCentralEnabled:$enabled,entraSpaClientId:$spa);
    }
    public static function pilot():void
    {
        putenv('PILOT_MODE=true');putenv('PILOT_ALLOWED_EMAILS=[]');putenv('PILOT_ALLOWED_SMS=[]');putenv('MAIL_ENABLED=false');putenv('SMS_ENABLED=false');
        $wf=self::policy('workforce');$cl=self::policy('client');
        putenv('PILOT_ALLOWED_IDENTITIES='.json_encode([['adapter'=>'entra-workforce','issuer'=>$wf->issuer(),'subject'=>self::oid('workforce')],['adapter'=>'entra-customer','issuer'=>$cl->issuer(),'subject'=>self::oid('client')]],JSON_THROW_ON_ERROR));
    }
    public static function seed(PDO $db):void
    {
        $schema=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');$end=strpos($schema,'CREATE TABLE form_library_entries');
        if($end===false)throw new RuntimeException('Missing schema baseline.');
        $db->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m','',substr($schema,0,$end)));
        foreach(['005_customer_onboarding.sql','039_separate_clinic_portals.sql','040_application_administration.sql','042_global_practitioners.sql','045_form_library.sql','046_practitioner_social_links.sql','047_practitioner_enquiries.sql','048_location_discovery.sql','049_access_audit_events.sql','050_consent_directives.sql','053_subscription_foundation.sql','054_billing_administration.sql','055_practitioner_applications.sql','058_practitioner_pilot_access.sql','059_browser_sessions.sql','060_browser_session_integration.sql'] as $file)$db->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/'.$file));
        $db->exec("INSERT INTO clinics(id,name) VALUES(1,'Synthetic A'),(2,'Synthetic B'); INSERT INTO clinic_hosts(host,clinic_id) VALUES('clinic-a.session.test',1),('clinic-b.session.test',2);
          INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(1,1,'admin@example.test','Synthetic Admin','staff','active'),(2,1,'microsoft@example.test','Synthetic Microsoft','staff','active'),(3,1,'client@example.test','Synthetic Client','client','active'),(5,1,'google@example.test','Synthetic Google','staff','active');
          INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'; INSERT INTO user_roles(user_id,role_id) SELECT u.id,r.id FROM users u JOIN roles r ON r.code='practitioner' WHERE u.id IN (2,5);
          INSERT INTO practitioners(user_id,discipline) VALUES(2,'Synthetic'),(5,'Synthetic'); INSERT INTO client_profiles(user_id) VALUES(3)");
        $wf=self::policy('workforce');$pr=self::policy('practitioner');$cl=self::policy('client');
        $db->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(1,'microsoft',?,?)")->execute([$wf->tenant,self::oid('workforce')]);
        $db->prepare('INSERT INTO application_administrators(id,issuer,subject,display_name,email) VALUES(1,?,?,?,?)')->execute([$wf->issuer(),self::oid('workforce'),'Synthetic Admin','admin@example.test']);
        foreach([2=>'microsoft',5=>'google',6=>'applicant'] as $id=>$profile){
            $db->prepare("INSERT INTO product_identities(id,adapter,issuer,subject) VALUES(?,'entra-external-staff',?,?)")->execute([$id,$pr->issuer(),'api-'.$profile]);
            if($id!==6)$db->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,1,?,'active')")->execute([$id,$id]);
            $db->prepare("INSERT INTO practitioner_pilot_access(clinic_id,identity_id,label,status,created_by,decided_by,approved_at) VALUES(1,?,?,'approved',1,1,UTC_TIMESTAMP())")->execute([$id,'Synthetic '.$profile]);
        }
        $hash=$cl->principalHash(self::claims('client'));
        $db->prepare('INSERT INTO customer_identities(id,identity_hash,issuer,subject,created_at) VALUES(1,?,?,?,UTC_TIMESTAMP())')->execute([$hash,$cl->issuer(),'api-client']);
        $db->exec('INSERT INTO customer_client_links(identity_id,client_id,clinic_id,created_at) VALUES(1,3,1,UTC_TIMESTAMP())');
        $db->exec("INSERT INTO locations(id,clinic_id,name,timezone) VALUES(1,1,'Synthetic virtual location','America/Toronto'); INSERT INTO subscription_plan_versions(id,code,version,scope,currency,features,display_name) VALUES(1,'session-rehearsal',1,'practitioner','CAD','{}','Synthetic session rehearsal')");
    }
}
