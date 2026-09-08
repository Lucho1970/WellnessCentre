<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;

final class AuditLogger
{
    public function __construct(private readonly Database $database) {}

    public function write(int $clinicId, ?AuthContext $actor, string $correlationId, string $action, string $entityType, ?int $entityId, string $outcome = 'success', array $metadata = []): void
    {
        $sql = 'INSERT INTO audit_logs(clinic_id,actor_user_id,correlation_id,action,entity_type,entity_id,outcome,ip_address,user_agent,metadata) VALUES(:clinic,:actor,:correlation,:action,:type,:entity,:outcome,INET6_ATON(:ip),:agent,:metadata)';
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute(['clinic'=>$clinicId,'actor'=>$actor?->userId,'correlation'=>$correlationId,'action'=>$action,'type'=>$entityType,'entity'=>$entityId,'outcome'=>$outcome,'ip'=>$_SERVER['REMOTE_ADDR'] ?? null,'agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,500),'metadata'=>$metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null]);
    }
}
