<?php
declare(strict_types=1);
namespace Wellness\Service;

/** Current server-resolved local references; never supplied by a browser. */
final readonly class BrowserSessionAuthority
{
    public function __construct(public ?int $actorId, public ?int $localUserId=null, public ?int $productIdentityId=null, public ?int $applicationAdministratorId=null) {}
    public function columns(): array { return ['local_user_id'=>$this->localUserId,'product_identity_id'=>$this->productIdentityId,'application_administrator_id'=>$this->applicationAdministratorId]; }
}
