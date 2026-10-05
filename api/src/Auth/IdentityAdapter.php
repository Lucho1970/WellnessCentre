<?php
declare(strict_types=1);
namespace Wellness\Auth;

interface IdentityAdapter
{
    public function verify(?string $token): VerifiedIdentity;
}
