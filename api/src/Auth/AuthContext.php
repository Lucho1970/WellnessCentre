<?php
declare(strict_types=1);

namespace Wellness\Auth;

final readonly class AuthContext
{
    public function __construct(
        public int $userId,
        public int $clinicId,
        public string $externalObjectId,
        public string $email,
        public string $displayName,
        public string $userType,
        public array $roles,
        public array $permissions = [],
    ) {}

    public function hasAnyRole(string ...$roles): bool
    {
        return count(array_intersect($this->roles, $roles)) > 0;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
