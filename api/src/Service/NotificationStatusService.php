<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class NotificationStatusService
{
    private const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'canceled', 'needs_review', 'resolved'];

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
        $channel = (string)($query['channel'] ?? '');
        if ($channel !== '' && !in_array($channel, ['email', 'sms'], true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification channel.');
        }
        $period = (string)($query['period'] ?? '');
        if ($period !== '' && !in_array($period, ['today', 'last7', 'week'], true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification date filter.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new ApiException(422, 'validation_error', 'Invalid page number.');
        }
        $limit = 25;
        $pdo = $this->database->connection();
        $filters = 'n.clinic_id=:clinic';
        $filterParams = ['clinic' => $actor->clinicId];
        if ($channel !== '') { $filters .= ' AND n.channel=:channel'; $filterParams['channel'] = $channel; }
        if ($period !== '') {
            $timezoneQuery = $pdo->prepare('SELECT timezone FROM locations WHERE clinic_id=:clinic AND is_bookable=1 ORDER BY id LIMIT 1');
            $timezoneQuery->execute(['clinic' => $actor->clinicId]);
            $timezone = (string)($timezoneQuery->fetchColumn() ?: 'America/Toronto');
            try { new DateTimeZone($timezone); } catch (Throwable) { $timezone = 'America/Toronto'; }
            [$from, $to] = NotificationActivityWindow::bounds($period, $timezone);
            $activityTime = "(CASE WHEN n.status IN ('sent','delivered') THEN n.sent_at ELSE n.scheduled_at END)";
            $filters .= " AND {$activityTime}>=:from_time AND {$activityTime}<:to_time";
            $filterParams['from_time'] = $from;
            $filterParams['to_time'] = $to;
        }
        $counts = array_fill_keys(self::STATUSES, 0);
        $summary = $pdo->prepare("SELECT n.status, COUNT(*) AS total FROM notification_events n WHERE {$filters} GROUP BY n.status");
        $summary->execute($filterParams);
        foreach ($summary->fetchAll() as $row) $counts[$row['status']] = (int)$row['total'];

        $sql = 'SELECT n.id,n.appointment_id,n.recipient_address,n.event_code,n.channel,n.status,n.scheduled_at,n.next_attempt_at,n.sent_at,n.attempt_count,n.last_error,(SELECT r.outcome FROM notification_reviews r WHERE r.notification_event_id=n.id AND r.clinic_id=n.clinic_id ORDER BY r.id DESC LIMIT 1) review_outcome FROM notification_events n WHERE '.$filters;
        if ($status !== '') $sql .= ' AND n.status=:status';
        $sql .= ' ORDER BY n.id DESC LIMIT :limit OFFSET :offset';
        $statement = $pdo->prepare($sql);
        foreach ($filterParams as $key => $value) $statement->bindValue(':'.$key, $value, $key === 'clinic' ? PDO::PARAM_INT : PDO::PARAM_STR);
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
            'health' => (new NotificationSchedulerHealth($this->database))->summary($actor),
        ];
    }

    public function practitionerList(AuthContext $actor, array $query): array
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('practitioner')) {
            throw new ApiException(403, 'forbidden', 'Practitioner access is required.');
        }
        $status = (string)($query['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification status.');
        }
        $channel = (string)($query['channel'] ?? '');
        if ($channel !== '' && !in_array($channel, ['email', 'sms'], true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification channel.');
        }
        $period = (string)($query['period'] ?? '');
        if ($period !== '' && !in_array($period, ['today', 'last7'], true)) {
            throw new ApiException(422, 'validation_error', 'Invalid notification date filter.');
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new ApiException(422, 'validation_error', 'Invalid page number.');
        }

        $pdo = $this->database->connection();
        $where = "n.clinic_id=:clinic AND n.recipient_user_id=:recipient AND n.event_code IN ('staff_booking_confirmation','staff_booking_change','staff_booking_cancellation','staff_booking_reassigned_away')";
        $params = ['clinic' => $actor->clinicId, 'recipient' => $actor->userId];
        if ($status !== '') { $where .= ' AND n.status=:status'; $params['status'] = $status; }
        if ($channel !== '') { $where .= ' AND n.channel=:channel'; $params['channel'] = $channel; }
        if ($period !== '') {
            $timezoneQuery = $pdo->prepare('SELECT timezone FROM locations WHERE clinic_id=:clinic AND is_bookable=1 ORDER BY id LIMIT 1');
            $timezoneQuery->execute(['clinic' => $actor->clinicId]);
            $timezone = (string)($timezoneQuery->fetchColumn() ?: 'America/Toronto');
            try { new DateTimeZone($timezone); } catch (Throwable) { $timezone = 'America/Toronto'; }
            [$from, $to] = NotificationActivityWindow::bounds($period, $timezone);
            $activityTime = "(CASE WHEN n.status IN ('sent','delivered') THEN n.sent_at ELSE n.scheduled_at END)";
            $where .= " AND {$activityTime}>=:from_time AND {$activityTime}<:to_time";
            $params['from_time'] = $from;
            $params['to_time'] = $to;
        }

        $totalQuery = $pdo->prepare("SELECT COUNT(*) FROM notification_events n WHERE {$where}");
        $totalQuery->execute($params);
        $total = (int)$totalQuery->fetchColumn();
        $limit = 25;
        $statement = $pdo->prepare("SELECT n.id,n.appointment_id,n.recipient_address,n.event_code,n.channel,n.status,n.scheduled_at,n.sent_at FROM notification_events n WHERE {$where} ORDER BY n.id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue(':'.$key, $value, in_array($key, ['clinic', 'recipient'], true) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => $statement->fetchAll(), 'total' => $total, 'page' => $page, 'page_size' => $limit];
    }

}
