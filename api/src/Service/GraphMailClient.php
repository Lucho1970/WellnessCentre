<?php
declare(strict_types=1);

namespace Wellness\Service;

use RuntimeException;

final class GraphMailClient
{
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $fromAddress,
    ) {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || !preg_match('/^[0-9a-f-]{36}$/i', $clientId)
            || $clientSecret === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Microsoft Graph mail configuration is incomplete.');
        }
    }

    public function senderAddress(): string { return $this->fromAddress; }

    public function send(string $recipient, string $subject, string $content, ?string $calendar = null): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new MailSendException('Recipient email address is invalid.', false);
        }
        $token = $this->accessToken();
        $body = json_encode(self::mailPayload($recipient, $subject, $content, $calendar), JSON_THROW_ON_ERROR);
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->fromAddress) . '/sendMail';
        [$status, $retryAfter] = $this->request($url, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ], $body, true);
        if ($status === 202) return;
        throw new MailSendException('Graph sendMail returned HTTP ' . $status . '.', $status === 429 || $status >= 500, false, $retryAfter);
    }

    public static function mailPayload(string $recipient, string $subject, string $content, ?string $calendar = null): array
    {
        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $content],
            'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
        ];
        if ($calendar !== null) $message['attachments'] = [[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'appointment.ics',
            'contentType' => 'text/calendar',
            'contentBytes' => base64_encode($calendar),
        ]];
        return ['message' => $message];
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) return $this->accessToken;
        $url = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $form = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986);
        [$status, , $response] = $this->request($url, ['Content-Type: application/x-www-form-urlencoded'], $form, false);
        if ($status !== 200) throw new MailSendException('Graph token request returned HTTP ' . $status . '.', $status === 429 || $status >= 500);
        $data = json_decode($response, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || (int)($data['expires_in'] ?? 0) < 60) {
            throw new MailSendException('Graph token response was invalid.', false);
        }
        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)$data['expires_in'];
        return $this->accessToken;
    }

    /** @return array{int,int,string} */
    private function request(string $url, array $headers, string $body, bool $mailRequest): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new MailSendException('Could not start Graph request.', !$mailRequest, $mailRequest);
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false || $status === 0) {
            // Once sendMail is attempted, a network failure may hide a successful 202.
            // Human review is safer than an automatic duplicate send.
            throw new MailSendException('Graph connection ended without a response.', !$mailRequest, $mailRequest);
        }
        $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
            ? min(3600, (int)$responseHeaders['retry-after']) : 0;
        return [$status, $retryAfter, (string)$response];
    }
}
