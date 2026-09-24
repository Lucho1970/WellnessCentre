<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class StaffNotificationPreferences
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public function get(AuthContext $actor): array
    {
        $this->staff($actor);
        $query = $this->database->connection()->prepare("SELECT u.email work_email,p.email_enabled,p.email_destination,p.personal_email,p.personal_email_verified_at,p.mobile_phone,p.sms_requested FROM users u LEFT JOIN staff_notification_preferences p ON p.user_id=u.id WHERE u.id=:user AND u.clinic_id=:clinic AND u.user_type='staff'");
        $query->execute(['user' => $actor->userId, 'clinic' => $actor->clinicId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ApiException(404, 'staff_not_found', 'Staff account not found.');
        return [
            'work_email' => $row['work_email'],
            'email_enabled' => (bool)($row['email_enabled'] ?? false),
            'email_destination' => $row['email_destination'] ?? 'work',
            'personal_email' => $row['personal_email'],
            'personal_email_verified' => $row['personal_email_verified_at'] !== null,
            'mobile_phone' => $row['mobile_phone'],
            'sms_requested' => (bool)($row['sms_requested'] ?? false),
            'sms_delivery_active' => false,
        ];
    }

    public function save(AuthContext $actor, array $body, string $correlationId): array
    {
        $this->staff($actor);
        $emailEnabled = $body['email_enabled'] ?? null;
        $smsRequested = $body['sms_requested'] ?? null;
        $destination = $body['email_destination'] ?? null;
        if (!is_bool($emailEnabled) || !is_bool($smsRequested) || !in_array($destination, ['work', 'personal', 'both'], true)) {
            throw new ApiException(422, 'validation_error', 'Choose valid notification preferences.');
        }
        $personal = strtolower(trim((string)($body['personal_email'] ?? '')));
        if ($personal !== '' && (strlen($personal) > 190 || !filter_var($personal, FILTER_VALIDATE_EMAIL))) {
            throw new ApiException(422, 'validation_error', 'Enter a valid personal email address.');
        }
        $mobileInput = preg_replace('/[\s().-]+/', '', trim((string)($body['mobile_phone'] ?? '')));
        $mobile = $mobileInput === '' ? null : (str_starts_with($mobileInput, '+1') ? $mobileInput : '+1' . ltrim($mobileInput, '1'));
        if ($mobile !== null && !preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $mobile)) {
            throw new ApiException(422, 'validation_error', 'Enter a valid Canadian or US mobile number.');
        }
        if ($smsRequested && $mobile === null) throw new ApiException(422, 'validation_error', 'A mobile number is required to request SMS notices.');

        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $user = $pdo->prepare("SELECT email FROM users WHERE id=:user AND clinic_id=:clinic AND user_type='staff' AND status='active' FOR UPDATE");
            $user->execute(['user' => $actor->userId, 'clinic' => $actor->clinicId]);
            $workEmail = $user->fetchColumn();
            if (!$workEmail) throw new ApiException(404, 'staff_not_found', 'Active staff account not found.');
            if ($personal !== '' && strcasecmp($personal, (string)$workEmail) === 0) throw new ApiException(422, 'validation_error', 'The personal email must differ from the sign-in email.');
            $existing = $pdo->prepare('SELECT email_enabled,email_destination,personal_email,personal_email_verified_at,mobile_phone,sms_requested FROM staff_notification_preferences WHERE user_id=:user FOR UPDATE');
            $existing->execute(['user' => $actor->userId]);
            $old = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
            $verified = $personal !== '' && strcasecmp((string)($old['personal_email'] ?? ''), $personal) === 0 && $old['personal_email_verified_at'] !== null;
            if ($emailEnabled && $destination !== 'work' && !$verified) throw new ApiException(422, 'email_not_verified', 'Verify the personal email before selecting it for notices.');
            $values = ['user' => $actor->userId, 'enabled' => $emailEnabled ? 1 : 0, 'destination' => $destination, 'personal' => $personal === '' ? null : $personal, 'verified' => $verified ? $old['personal_email_verified_at'] : null, 'mobile' => $mobile, 'sms' => $smsRequested ? 1 : 0];
            if ($old === null) {
                $save = $pdo->prepare("INSERT INTO staff_notification_preferences(user_id,email_enabled,email_destination,personal_email,personal_email_verified_at,mobile_phone,sms_requested,sms_requested_at) VALUES(:user,:enabled,:destination,:personal,:verified,:mobile,:sms,CASE WHEN :sms_at=1 THEN UTC_TIMESTAMP() ELSE NULL END)");
                $save->execute($values + ['sms_at' => $smsRequested ? 1 : 0]);
            } else {
                $changed = strcasecmp((string)($old['personal_email'] ?? ''), $personal) !== 0;
                $save = $pdo->prepare("UPDATE staff_notification_preferences SET email_enabled=:enabled,email_destination=:destination,personal_email=:personal,personal_email_verified_at=:verified,mobile_phone=:mobile,sms_requested=:sms,sms_requested_at=CASE WHEN :sms_at=0 THEN NULL WHEN :sms_time=1 THEN UTC_TIMESTAMP() ELSE sms_requested_at END,email_code_hash=CASE WHEN :changed_hash=1 THEN NULL ELSE email_code_hash END,email_code_expires_at=CASE WHEN :changed_expiry=1 THEN NULL ELSE email_code_expires_at END,email_code_sent_at=CASE WHEN :changed_sent=1 THEN NULL ELSE email_code_sent_at END,email_code_attempts=CASE WHEN :changed_attempts=1 THEN 0 ELSE email_code_attempts END WHERE user_id=:user");
                $save->execute($values + ['sms_at' => $smsRequested ? 1 : 0, 'sms_time' => $smsRequested && (!(bool)$old['sms_requested'] || $mobile !== $old['mobile_phone']) ? 1 : 0, 'changed_hash' => $changed ? 1 : 0, 'changed_expiry' => $changed ? 1 : 0, 'changed_sent' => $changed ? 1 : 0, 'changed_attempts' => $changed ? 1 : 0]);
            }
            if ($old !== null && ((bool)$old['email_enabled'] !== $emailEnabled || $old['email_destination'] !== $destination || strcasecmp((string)($old['personal_email'] ?? ''), $personal) !== 0)) {
                $cancel = $pdo->prepare("UPDATE notification_events SET status='canceled',next_attempt_at=NULL,last_error='Staff delivery preference changed' WHERE recipient_user_id=:user AND channel='email' AND event_code IN ('staff_booking_confirmation','staff_booking_change','staff_booking_cancellation') AND status IN ('queued','failed')");
                $cancel->execute(['user' => $actor->userId]);
            }
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'staff.notification_preferences.update', 'user', $actor->userId, 'success', ['email_enabled' => $emailEnabled, 'email_destination' => $destination, 'sms_requested' => $smsRequested]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($actor);
    }

    public function sendVerification(AuthContext $actor, string $correlationId): array
    {
        $this->staff($actor);
        if (!filter_var($_ENV['MAIL_ENABLED'] ?? getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            throw new ApiException(503, 'mail_unavailable', 'Email delivery is not enabled.');
        }
        $pdo = $this->database->connection();
        $query = $pdo->prepare('SELECT personal_email,personal_email_verified_at,email_code_sent_at FROM staff_notification_preferences WHERE user_id=:user');
        $query->execute(['user' => $actor->userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['personal_email'] || $row['personal_email_verified_at']) throw new ApiException(422, 'validation_error', 'Save a new personal email address before requesting verification.');
        if ($row['email_code_sent_at'] && strtotime($row['email_code_sent_at'] . ' UTC') > time() - 60) throw new ApiException(429, 'rate_limited', 'Wait one minute before requesting another code.');
        $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $update = $pdo->prepare('UPDATE staff_notification_preferences SET email_code_hash=:hash,email_code_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),email_code_sent_at=UTC_TIMESTAMP(),email_code_attempts=0 WHERE user_id=:user AND personal_email=:email AND personal_email_verified_at IS NULL AND (email_code_sent_at IS NULL OR email_code_sent_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))');
        $update->execute(['hash' => password_hash($code, PASSWORD_DEFAULT), 'user' => $actor->userId, 'email' => $row['personal_email']]);
        if ($update->rowCount() !== 1) throw new ApiException(409, 'preference_changed', 'Reload your preferences and try again.');
        $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
        try {
            $mailer = new GraphMailClient($env('MAIL_TENANT_ID'), $env('MAIL_CLIENT_ID'), $env('MAIL_CLIENT_SECRET'), $env('MAIL_FROM_ADDRESS'));
            $mailer->send((string)$row['personal_email'], 'Verify your wellness staff notification email / Vérifiez votre courriel de notification', "Your verification code is {$code}. It expires in 15 minutes. If you did not request this, ignore it.\n\nVotre code de vérification est {$code}. Il expire dans 15 minutes. Si vous n'en avez pas fait la demande, ignorez ce message.");
        } catch (Throwable $e) {
            throw new ApiException(503, 'mail_unavailable', 'Verification mail could not be submitted. Try again later.');
        }
        $this->audit->write($actor->clinicId, $actor, $correlationId, 'staff.notification_email.verify_requested', 'user', $actor->userId);
        return ['sent' => true];
    }

    public function verify(AuthContext $actor, array $body, string $correlationId): array
    {
        $this->staff($actor);
        $code = $body['code'] ?? null;
        if (!is_string($code) || !preg_match('/^\d{8}$/', $code)) throw new ApiException(422, 'validation_error', 'Enter the eight-digit verification code.');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare('SELECT email_code_hash,email_code_expires_at,email_code_attempts FROM staff_notification_preferences WHERE user_id=:user FOR UPDATE');
            $query->execute(['user' => $actor->userId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['email_code_hash'] || !$row['email_code_expires_at'] || strtotime($row['email_code_expires_at'] . ' UTC') < time() || (int)$row['email_code_attempts'] >= 5) throw new ApiException(422, 'code_expired', 'Request a new verification code.');
            if (!password_verify($code, (string)$row['email_code_hash'])) {
                $pdo->prepare('UPDATE staff_notification_preferences SET email_code_attempts=email_code_attempts+1 WHERE user_id=:user')->execute(['user' => $actor->userId]);
                $pdo->commit();
                throw new ApiException(422, 'invalid_code', 'The verification code is incorrect.');
            }
            $pdo->prepare('UPDATE staff_notification_preferences SET personal_email_verified_at=UTC_TIMESTAMP(),email_code_hash=NULL,email_code_expires_at=NULL,email_code_sent_at=NULL,email_code_attempts=0 WHERE user_id=:user')->execute(['user' => $actor->userId]);
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'staff.notification_email.verified', 'user', $actor->userId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($actor);
    }

    private function staff(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff') throw new ApiException(403, 'forbidden', 'Staff sign-in is required.');
    }
}
