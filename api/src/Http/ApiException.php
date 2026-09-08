<?php
declare(strict_types=1);

namespace Wellness\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) { parent::__construct($message); }
}
