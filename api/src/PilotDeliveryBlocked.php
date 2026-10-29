<?php
declare(strict_types=1);

namespace Wellness;

/** A known no-send outcome, not a provider timeout or delivery acceptance. */
final class PilotDeliveryBlocked extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pilot delivery blocked: destination is not approved.');
    }
}
