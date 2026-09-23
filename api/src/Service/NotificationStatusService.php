<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class NotificationStatusService
{
    private const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'canceled', 'needs_review'];

    public function __construct(private readonly Database $database) {}

    public function list(AuthContext $actor, array $query): array
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin')) {
            throw new ApiException(403, 'forbidden', 'Administrator access is required.');
        }
        $status = (string)($query['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification status.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new ApiException(422, 'validation_error', 'Invalid page number.');
        }
        $limit = 25;
        $pdo = $this->database->connection();
        $counts = array_fill_keys(self::STATUSES, 0);
        $summary = $pdo->prepare('SELECT status, COUNT(*) AS total FROM notification_events WHERE clinic_id=:clinic AND channel=\'email\' GROUP BY status');
        $summary->execute(['clinic' => $actor->clinicId]);
        foreach ($summary->fetchAll() as $row) $counts[$row['status']] = (int)$row['total'];

        $sql = 'SELECT n.id,n.appointment_id,n.recipient_address,n.event_code,n.channel,n.status,n.scheduled_at,n.next_attempt_at,n.sent_at,n.attempt_count,n.last_error FROM notification_events n WHERE n.clinic_id=:clinic AND n.channel=\'email\'';
        if ($status !== '') $sql .= ' AND n.status=:status';
        $sql .= ' ORDER BY n.id DESC LIMIT :limit OFFSET :offset';
        $statement = $pdo->prepare($sql);
        $statement->bindValue(':clinic', $actor->clinicId, PDO::PARAM_INT);
        if ($status !== '') $statement->bindValue(':status', $status);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        return [
            'items' => $statement->fetchAll(),
            'counts' => $counts,
            'total' => $status === '' ? array_sum($counts) : $counts[$status],
            'page' => $page,
            'page_size' => $limit,
        ];
    }
}
