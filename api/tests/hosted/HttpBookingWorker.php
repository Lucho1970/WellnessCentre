<?php
declare(strict_types=1);

namespace Wellness\Tests\Hosted;

use PDO;
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AddressCoverageService;
use Wellness\Service\AuditLogger;
use Wellness\Service\BookingService;

final class HttpBookingWorker
{
    public static function authorized(string $secret, string $timestamp, string $signature, string $body): bool
    {
        if (strlen($secret) < 32 || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 30) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . "\n" . $body, $secret), $signature);
    }

    /** @return array<string,mixed> */
    public static function run(Config $config, array $input): array
    {
        $clinicId = (int)($input['clinic_id'] ?? 0);
        $actorId = (int)($input['actor_id'] ?? 0);
        $body = $input['body'] ?? null;
        if ($clinicId < 1 || $actorId < 1 || !is_array($body)) return ['ok' => false, 'status' => 400];
        $database = new Database($config);
        $pdo = $database->connection();
        $pdo->exec('SET innodb_lock_wait_timeout=10');
        $check = $pdo->prepare("SELECT 1 FROM clinics c JOIN users u ON u.clinic_id=c.id WHERE c.id=:clinic AND u.id=:actor AND c.name LIKE 'Synthetic booking race %' AND c.status='active' AND u.user_type='staff' AND u.display_name='Synthetic staff' AND u.email LIKE '%@booking-test' AND u.status='active'");
        $check->execute(['clinic' => $clinicId, 'actor' => $actorId]);
        if ((int)$check->fetchColumn() !== 1) return ['ok' => false, 'status' => 403];
        try {
            $actor = new AuthContext($actorId, $clinicId, '', 'staff@booking-test', 'Synthetic Staff', 'staff', ['super_admin']);
            $booking = new BookingService($database, new AuditLogger($database), new AddressCoverageService($database, $config));
            $result = $booking->create($actor, $body, '00000000-0000-4000-8000-000000000001');
            return ['ok' => true, 'id' => (int)$result['id']];
        } catch (ApiException $error) {
            return ['ok' => false, 'status' => $error->status, 'code' => $error->errorCode];
        } catch (\Throwable $error) {
            error_log('Hosted booking worker failed: ' . get_class($error));
            return ['ok' => false, 'unexpected' => get_class($error)];
        }
    }
}
