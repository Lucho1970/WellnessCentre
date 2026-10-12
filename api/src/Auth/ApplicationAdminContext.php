<?php
declare(strict_types=1);
namespace Wellness\Auth;

/** An explicit global grant; deliberately not a clinic AuthContext. */
final readonly class ApplicationAdminContext
{
    public function __construct(public int $id, public VerifiedIdentity $identity, public string $displayName, public string $email) {}
}
