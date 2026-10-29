<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;

/** Explicit per-clinic Graph sender. Never falls back to a different clinic's identity. */
final class ClinicMailRouter
{
    public function __construct(private readonly PDO $pdo,private readonly GraphMailClient $transport) {}
    public function route(int $clinic,?int $appointment=null,bool $staffNotice=false): array
    {
        $q=$this->pdo->prepare("SELECT s.* FROM clinic_communications s JOIN clinics c ON c.id=s.clinic_id AND c.status='active' WHERE s.clinic_id=? AND s.email_enabled=1");$q->execute([$clinic]);$s=$q->fetch();
        if(!$s || !filter_var($s['mail_from'],FILTER_VALIDATE_EMAIL) || !filter_var($s['reply_to'],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Clinic mail sender is not ready.');
        $reply=$s['reply_to'];
        if($appointment!==null&&!$staffNotice){
            $q=$this->pdo->prepare("SELECT r.email FROM appointments a JOIN practitioners pr ON pr.id=a.practitioner_id AND pr.active=1 JOIN users u ON u.id=pr.user_id AND u.clinic_id=a.clinic_id AND u.status='active' JOIN staff_memberships m ON m.local_user_id=u.id AND m.clinic_id=u.clinic_id AND m.status='active' JOIN product_identities i ON i.id=m.identity_id AND i.status='active' JOIN practitioner_reply_addresses r ON r.membership_id=m.id AND r.clinic_id=m.clinic_id AND r.active=1 WHERE a.id=? AND a.clinic_id=?");$q->execute([$appointment,$clinic]);$practitioner=$q->fetch();
            $candidate=$practitioner?$practitioner['email']:null;
            if(is_string($candidate)&&filter_var($candidate,FILTER_VALIDATE_EMAIL))$reply=$candidate;
        }
        return ['mailer'=>$this->transport->forSender($s['mail_from']),'reply_to'=>$reply];
    }
}
