<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Database;
use Wellness\Http\Response;

/** Best-effort post-response delivery; the scheduled worker remains the fallback. */
final class ImmediateNotificationDispatch
{
    public static function schedule(Database $database, int $appointmentId): void
    {
        $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
        if (!filter_var($env('MAIL_ENABLED'), FILTER_VALIDATE_BOOL)) return;
        Response::afterJson(static function () use ($database, $appointmentId, $env): void {
            $portalUrl = $env('CLIENT_PORTAL_URL');
            if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || !str_starts_with($portalUrl, 'https://')) {
                throw new \RuntimeException('Client portal URL is invalid.');
            }
            $mailer = new GraphMailClient(
                $env('MAIL_TENANT_ID'), $env('MAIL_CLIENT_ID'),
                $env('MAIL_CLIENT_SECRET'), $env('MAIL_FROM_ADDRESS')
            );
            $sms = filter_var($env('SMS_ENABLED'), FILTER_VALIDATE_BOOL)
                ? new VoipMsSmsClient($env('VOIPMS_API_USERNAME'), $env('VOIPMS_API_PASSWORD'), $env('VOIPMS_FROM_DID'))
                : null;
            $result = (new NotificationWorker($database->connection(), $mailer, $portalUrl, $sms))
                ->run(4, $appointmentId);
            error_log(sprintf(
                'Wellness immediate notification: sent=%d retry=%d review=%d canceled=%d',
                $result['sent'], $result['retry'], $result['review'], $result['canceled']
            ));
        });
    }
}
