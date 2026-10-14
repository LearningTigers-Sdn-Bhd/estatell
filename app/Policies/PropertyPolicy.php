<?php

namespace App\Policies;

use App\Enums\PartnerStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use Illuminate\Auth\Access\Response;

class PropertyPolicy
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Whether the user may view the property as its staff: an admin for any property, a partner user for a property
     * of their Active partner. While impersonating, the support user behind the session is the one checked. Others
     * get a 404, so a partner cannot tell that another partner's code exists.
     */
    public function viewAs(User $user, Property $property): Response
    {
        $supportUser = $this->impersonation->supportUser() ?? $user;

        $allowed = $supportUser->is_admin
            || ($supportUser->partner_id !== null
                && $property->partner_id === $supportUser->partner_id
                && $supportUser->partner?->status === PartnerStatus::Active);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
