<?php

namespace App\Services\Platform;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The name a hotel sees for the user behind an activity log entry. Platform admins show as "estatell Support", never
 * by name, also when they wrote while viewing the property as its staff. Staff and partner users keep their names.
 * See docs/01-platform/04-impersonation.md#audit.
 */
class HotelFacingCauser
{
    /**
     * Null when the causer is not a user (the system, or a deleted user).
     */
    public function name(?Model $causer): ?string
    {
        if (! $causer instanceof User) {
            return null;
        }

        return $causer->is_admin ? __('estatell Support') : $causer->name;
    }
}
