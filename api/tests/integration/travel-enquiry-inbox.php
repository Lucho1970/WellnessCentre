<?php
declare(strict_types=1);
// Disposable localhost schemas only; exercise production inbox SQL on MySQL 5.7 and MariaDB.
require __DIR__.'/booking-client-access.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\{LocationDiscoveryService,TravelEnquiryService};

$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/048_location_discovery.sql'));
$pdo->exec("INSERT INTO locations(id,clinic_id,name) VALUES(2,2,'Foreign synthetic location');
 INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(2,2,'foreign-inbox','Foreign service',100);
 INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,101,'staff_access',2,1,1)");
$inbox=new TravelEnquiryService($database,new LocationDiscoveryService($database,$config));
$inboxChecks=0;
$expectInbox=function(bool $ok,string $why)use(&$inboxChecks):void{if(!$ok)throw new RuntimeException($why);$inboxChecks++;};
$referenceInbox=static fn():string=>vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));
$messageInbox=function(int $thread,int $sender)use($pdo,$referenceInbox):int{
 $q=$pdo->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)');
 $q->execute([$thread,$sender,$referenceInbox(),'Synthetic message']);return (int)$pdo->lastInsertId();
};
$threadInbox=function(int $client,int $practitioner,int $clinic=1)use($pdo,$referenceInbox,$messageInbox):int{
 $reference=$referenceInbox();$q=$pdo->prepare('INSERT INTO travel_enquiries(clinic_id,client_id,practitioner_id,service_id,location_id,reference,request_hash,search_label) VALUES(?,?,?,?,?,?,?,?)');
 $q->execute([$clinic,$client,$practitioner,$clinic,$clinic,$reference,hash('sha256',$reference),'Synthetic area']);$id=(int)$pdo->lastInsertId();$messageInbox($id,$client);return $id;
};
$ownThread=$threadInbox(104,2);$restrictedThread=$threadInbox(101,2);$otherThread=$threadInbox(104,3);$foreignThread=$threadInbox(105,5,2);
$clinicReply=$messageInbox($ownThread,2);
// Invalid senders must not manufacture an unread notification for the patient.
$messageInbox($otherThread,5);$messageInbox($otherThread,101);
$staffInbox=new AuthContext(2,1,'','','','staff',['practitioner']);
$adminInbox=new AuthContext(1,1,'','','','staff',['super_admin']);
$clientInbox=new AuthContext(104,1,'','','','client',[]);
$patientHistoryInbox=new AuthContext(101,1,'','','','client',[]);
$expectCount=function(AuthContext $who,int $expected,string $why)use($inbox,$expectInbox):void{
 $expectInbox($inbox->unreadCount($who)===['unread_count'=>$expected],$why.'; response contains only a count');
};
$inboxIds=static fn(array $result):array=>array_map('intval',array_column($result['items'],'id'));
$inboxUnread=static function(array $result,int $id):bool{foreach($result['items'] as $row)if((int)$row['id']===$id)return (bool)$row['unread'];throw new RuntimeException('Expected thread missing');};
$result=$inbox->list($staffInbox);
$expectInbox($inboxIds($result)===[$ownThread]&&$result['unread_count']===1&&$inboxUnread($result,$ownThread),'Practitioner inbox conceals restricted, other-practitioner and foreign-clinic threads');
$expectCount($staffInbox,1,'Count conceals named-restricted, foreign and other-practitioner threads');
$result=$inbox->list($adminInbox);
$expectInbox($inboxIds($result)===[$otherThread,$restrictedThread,$ownThread]&&$result['unread_count']===3,'Clinic administrator sees only permitted clinic threads');
$expectCount($adminInbox,3,'Administrator count is clinic scoped');
foreach(['clinic_admin','reception'] as $role)$expectCount(new AuthContext(3,1,'','','','staff',[$role]),3,'Permitted '.$role.' count retains clinic scope');
$result=$inbox->list($clientInbox);
$expectInbox($inboxIds($result)===[$otherThread,$ownThread]&&$result['unread_count']===1,'Patient inbox retains own threads and counts only valid clinic replies');
$expectCount($clientInbox,1,'Patient count excludes invalid senders and other patients');
$expectInbox(!$inboxUnread($result,$otherThread)&&$inboxUnread($result,$ownThread),'Foreign staff and unrelated patients cannot contribute to unread flags');
$result=$inbox->list($patientHistoryInbox);
$expectInbox($inboxIds($result)===[$restrictedThread]&&$result['unread_count']===0,'Staff restriction preserves patient self-history without exposing another patient');
$expectCount($patientHistoryInbox,0,'Named staff restriction preserves independent patient self-access');
$result=$inbox->list($adminInbox,['before'=>$restrictedThread]);
$expectInbox($inboxIds($result)===[$ownThread]&&$result['unread_count']===3,'Pagination filters rows while retaining the permitted all-page unread count');
$pdo->prepare('UPDATE travel_enquiries SET practitioner_seen_id=?,client_seen_id=? WHERE id=?')->execute([$clinicReply,$clinicReply,$ownThread]);
$result=$inbox->list($staffInbox);$expectInbox($result['unread_count']===0&&!$inboxUnread($result,$ownThread),'Staff read position suppresses already-seen patient messages');
$expectCount($staffInbox,0,'Count reflects staff read acknowledgement');
$result=$inbox->list($clientInbox);$expectInbox($result['unread_count']===0&&!$inboxUnread($result,$ownThread),'Patient read position suppresses already-seen clinic replies');
$expectCount($clientInbox,0,'Count reflects patient read acknowledgement');
$result=$inbox->list(new AuthContext(5,2,'','','','staff',['practitioner']));
$expectInbox($inboxIds($result)===[$foreignThread]&&$result['unread_count']===1,'Other clinic retains independent inbox isolation');
$expectCount(new AuthContext(5,2,'','','','staff',['practitioner']),1,'Foreign clinic has an independent count');
$threadInbox(107,2);$expectCount($adminInbox,2,'Merged client enquiries are excluded from counts');
$pdo->exec("INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(1,104,'staff_access',0,1,1)");
$expectCount($adminInbox,1,'Global restriction excludes affected threads even for administrators');
$expectCount($staffInbox,0,'All-restricted practitioner count is safely zero');
$expectCount($clientInbox,0,'Global staff restriction does not block patient self-access');
$pdo->exec("DELETE FROM client_consent_directives WHERE clinic_id=1 AND client_id=104 AND target_staff_id=0");
foreach(["UPDATE users SET status='inactive' WHERE id=2","UPDATE practitioners SET active=0 WHERE id=2"] as $disable){
 $pdo->exec($disable);$expectCount($staffInbox,0,'Inactive actor/profile has no counted threads');
 $pdo->exec("UPDATE users SET status='active' WHERE id=2;UPDATE practitioners SET active=1 WHERE id=2");
}
try{$inbox->unreadCount(new AuthContext(1,1,'','','','staff',['accountant']));throw new RuntimeException('Unauthorized count role accepted');}
catch(ApiException $e){$expectInbox($e->status===403,'Unauthorized role cannot read the count');}
try{$inbox->list(new AuthContext(1,1,'','','','staff',['accountant']));throw new RuntimeException('Unauthorized role accepted');}
catch(ApiException $e){$expectInbox($e->status===403,'Unauthorized role is denied');}
$pdo->exec('RENAME TABLE client_consent_directives TO retained_inbox_directives');
try{foreach(['list','unreadCount'] as $method)try{$inbox->$method($staffInbox);throw new RuntimeException('Missing privacy storage accepted');}catch(PDOException){$expectInbox(true,'Unavailable privacy storage fails closed for '.$method);}}
finally{$pdo->exec('RENAME TABLE retained_inbox_directives TO client_consent_directives');}
echo "Travel enquiry inbox compatibility: $inboxChecks checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
