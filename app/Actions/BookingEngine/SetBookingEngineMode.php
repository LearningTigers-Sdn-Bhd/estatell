<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * An admin sets how the property takes online bookings. The new mode starts offline: a published site is unpublished,
 * and leaving API only revokes the API token so the owner's website stops at once. Returns false, writing nothing, when
 * the Booking engine flag is off or the mode is unchanged. See docs/29-booking-engine/07-settings-and-permissions.md.
 */
class SetBookingEngineMode
{
    public function __construct(
        private SetUpBookingSite $setUpBookingSite,
        private UnpublishBookingSite $unpublishBookingSite,
        private RevokeBookingApiToken $revokeBookingApiToken,
    ) {}

    public function __invoke(User $admin, Property $property, BookingEngineMode $mode): bool
    {
        if (! Feature::for($property)->active(BookingEngine::class)) {
            return false;
        }

        return DB::transaction(function () use ($admin, $property, $mode): bool {
            $site = PropertyBookingSite::query()->lockForUpdate()->findOrFail(($this->setUpBookingSite)($property)->id);
            $previous = $site->mode;

            if ($previous === $mode) {
                return false;
            }

            ($this->unpublishBookingSite)($site, $admin);

            if ($previous === BookingEngineMode::ApiOnly) {
                ($this->revokeBookingApiToken)($admin, $property);
            }

            $site->forceFill(['mode' => $mode])->save();

            activity()
                ->performedOn($site)
                ->causedBy($admin)
                ->event('booking-website.mode-changed')
                ->withProperties(['property_id' => $property->id, 'from' => $previous->value, 'to' => $mode->value])
                ->log("Booking engine mode set to {$mode->label()}");

            return true;
        });
    }
}
