<?php
declare(strict_types=1);

namespace Wellness\Service;

use RuntimeException;

final class MailSendException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly bool $ambiguous = false, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
