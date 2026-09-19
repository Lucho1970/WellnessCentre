<?php
declare(strict_types=1);

namespace Wellness\Http;

final class Response
{
    private const STRING_IDENTIFIER_KEYS = ['object_id', 'tenant_id', 'correlation_id', 'external_id', 'source_event_id', 'home_account_id'];

    /**
     * PDO MySQL can return integer columns as strings. Keep the JSON contract
     * consistent by converting safe numeric values only when their field name
     * identifies a database ID. UUIDs and other external identifiers remain
     * strings because they are not unsigned integer values.
     */
    public static function normalizeNumericIds(mixed $value, bool $idContext = false): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $child) {
                $childIsId = $idContext || (is_string($key) && !in_array($key, self::STRING_IDENTIFIER_KEYS, true) && preg_match('/(^id$|_id$|_ids$)/', $key) === 1);
                $normalized[$key] = self::normalizeNumericIds($child, $childIsId);
            }
            return $normalized;
        }
        if ($idContext && is_string($value) && preg_match('/^(0|[1-9]\d*)$/D', $value) === 1) {
            $number = (int)$value;
            if ((string)$number === $value) return $number;
        }
        return $value;
    }

    public static function json(array $payload, int $status = 200, ?string $correlationId = null): never
    {
        http_response_code($status);
        if ($correlationId) header('X-Correlation-ID: ' . $correlationId);
        // A 204 response ends at the headers. Do not serialize even an empty
        // array: emitting "[]" can produce an invalid CGI/proxy response.
        if ($status === 204) {
            header_remove('Content-Type');
            header_remove('Content-Length');
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(self::normalizeNumericIds($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
