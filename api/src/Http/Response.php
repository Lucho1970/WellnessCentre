<?php
declare(strict_types=1);

namespace Wellness\Http;

final class Response
{
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
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
