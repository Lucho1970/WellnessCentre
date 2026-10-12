<?php
declare(strict_types=1);
namespace Wellness\Auth;

/** Construct only after provider signature, lifetime, issuer, audience and scope validation. */
final readonly class VerifiedIdentity
{
    public function __construct(
        public string $adapter,
        public string $issuer,
        public string $subject,
        public string $tenantId,
        public array $directoryRoles = [],
    ) {
        if ($adapter === '' || $issuer === '' || $subject === '') throw new \InvalidArgumentException('Incomplete verified identity.');
    }
}
