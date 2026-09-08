<?php
declare(strict_types=1);

namespace Wellness\Http;

final class Response
{
    public static function json(array $payload, int $status = 200, ?string $correlationId = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($correlationId) header('X-Correlation-ID: ' . $correlationId);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
