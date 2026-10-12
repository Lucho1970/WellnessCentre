<?php
declare(strict_types=1);
namespace Wellness\Auth;

use Wellness\Database;
use Wellness\Http\ApiException;

final class ApplicationAdminAuthenticator
{
    public function __construct(private readonly Database $database, private readonly EntraAuthenticator $entra) {}

    public function authenticate(?string $token): ApplicationAdminContext
    {
        $identity = $this->entra->verify($token);
        $query = $this->database->connection()->prepare("SELECT id,display_name,email FROM application_administrators WHERE issuer=? AND subject=? AND status='active'");
        $query->execute([$identity->issuer, $identity->subject]);
        $grant = $query->fetch();
        if (!$grant) throw new ApiException(403, 'application_admin_required', 'Application administrator access is required.');
        return new ApplicationAdminContext((int)$grant['id'], $identity, $grant['display_name'], $grant['email']);
    }
}
