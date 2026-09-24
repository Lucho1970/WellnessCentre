<?php
declare(strict_types=1);

namespace Wellness\Service;

use RuntimeException;

final class VoipMsSmsClient
{
    private const ENDPOINT = 'https://voip.ms/api/v1/rest.php';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromDid,
        private readonly ?\Closure $requester = null,
    ) {
        if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '' || !preg_match('/^[2-9]\d{9}$/', $fromDid)) {
            throw new RuntimeException('VoIP.ms SMS configuration is incomplete.');
        }
    }

    /** Returns the provider message ID when available. */
    public function send(string $recipient, string $message): ?string
    {
        if (!preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $recipient) || $message === '' || strlen($message) > 160 || preg_match('/[^\x20-\x7E]/', $message)) {
            throw new SmsSendException('SMS recipient or message is invalid.');
        }
        $body = http_build_query([
            'api_username' => $this->username,
            'api_password' => $this->password,
            'method' => 'sendSMS',
            'did' => $this->fromDid,
            'dst' => $recipient,
            'message' => $message,
            'content_type' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);
        [$status, $response] = $this->requester !== null ? ($this->requester)($body) : $this->request($body);
        // A timeout may occur after the provider accepted the SMS. Never auto-retry.
        if ($response === false || $status === 0) throw new SmsSendException('SMS provider connection ended without a response.');
        if ($status !== 200) throw new SmsSendException('SMS provider returned HTTP ' . $status . '.');
        $data = json_decode((string)$response, true);
        if (!is_array($data) || ($data['status'] ?? null) !== 'success') {
            // Do not retain provider responses: they may contain account details.
            $providerStatus = is_array($data) ? ($data['status'] ?? null) : null;
            $safeStatus = is_string($providerStatus) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $providerStatus)
                ? ' (' . $providerStatus . ')' : '';
            throw new SmsSendException('SMS provider did not confirm delivery acceptance' . $safeStatus . '.');
        }
        $id = $data['sms'] ?? null;
        return is_scalar($id) && (string)$id !== '' ? substr((string)$id, 0, 191) : null;
    }

    /** @return array{int,string|false} */
    private function request(string $body): array
    {
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) throw new SmsSendException('Could not start SMS request.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, $response];
    }
}
