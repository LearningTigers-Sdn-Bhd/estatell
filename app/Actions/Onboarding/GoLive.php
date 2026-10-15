<?php

namespace App\Actions\Onboarding;

use App\Actions\NightAudit\OpenBusinessDate;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Platform\PlatformSettings;
use App\Services\Property\PropertyToday;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GoLive
{
    public function __construct(
        private GoLiveReadiness $readiness,
        private OpenBusinessDate $openBusinessDate,
        private PlatformSettings $platformSettings,
        private PropertyToday $propertyToday,
        private RefreshPendingTemporaryPasswords $refreshPendingTemporaryPasswords,
    ) {}

    /**
     * Open the first business date and switch the property from setup to operation. Returns true when the property
     * is Live, or false when the property cannot go live (wrong status, a required step is not done, or it already
     * has a business date).
     *
     * An Onboarding property goes live only when the platform does not ask for approval, and its first business day
     * is today in the property time zone. A PendingApproval property goes live through ApproveGoLive, which may pass
     * an earlier opening date. Document numbers need no start step: each sequence row is created on first use. Going
     * Live also refreshes the temp passwords of staff who have not signed in yet.
     */
    public function __invoke(User $actor, Property $property, ?CarbonInterface $openingDate = null): bool
    {
        return DB::transaction(function () use ($actor, $property, $openingDate): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $previousStatus = $property->status;

            if (! $this->mayGoLive($property) || ! $this->readiness->isReady($property)) {
                return false;
            }

            $today = $this->propertyToday->date($property);
            $openingDate ??= $today;

            if ($openingDate->greaterThan($today) || ($this->openBusinessDate)($property, $openingDate, $actor) === null) {
                return false;
            }

            $property->update(['status' => PropertyStatus::Live, 'went_live_at' => now()]);

            ($this->refreshPendingTemporaryPasswords)($property, $actor);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.went-live')
                ->withProperties([
                    'from' => $previousStatus->value,
                    'to' => PropertyStatus::Live->value,
                    'opening_date' => $openingDate->toDateString(),
                ])
                ->log('Property went live');

            return true;
        });
    }

    private function mayGoLive(Property $property): bool
    {
        return match ($property->status) {
            PropertyStatus::Onboarding => ! $this->platformSettings->current()->require_go_live_approval,
            PropertyStatus::PendingApproval => true,
            default => false,
        };
    }
}
