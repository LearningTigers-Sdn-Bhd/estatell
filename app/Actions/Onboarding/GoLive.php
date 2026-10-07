<?php

namespace App\Actions\Onboarding;

use App\Actions\NightAudit\OpenBusinessDate;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GoLive
{
    public function __construct(
        private GoLiveReadiness $readiness,
        private OpenBusinessDate $openBusinessDate,
        private PlatformSettings $platformSettings,
    ) {}

    /**
     * Open the first business date and switch the property from setup to operation.
     *
     * Returns the new status (Live, or PendingApproval when the platform asks for approval), or null when the
     * property is not onboarding or a required step is not done. Document numbers need no start step: each
     * sequence row is created on first use.
     */
    public function __invoke(User $actor, Property $property, CarbonInterface $openingDate): ?PropertyStatus
    {
        return DB::transaction(function () use ($actor, $property, $openingDate): ?PropertyStatus {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->status !== PropertyStatus::Onboarding || ! $this->readiness->isReady($property)) {
                return null;
            }

            if (($this->openBusinessDate)($property, $openingDate, $actor) === null) {
                return null;
            }

            $status = $this->platformSettings->current()->require_go_live_approval
                ? PropertyStatus::PendingApproval
                : PropertyStatus::Live;

            $property->update([
                'status' => $status,
                'went_live_at' => $status === PropertyStatus::Live ? now() : null,
            ]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.went-live')
                ->withProperties([
                    'from' => PropertyStatus::Onboarding->value,
                    'to' => $status->value,
                    'opening_date' => $openingDate->toDateString(),
                ])
                ->log($status === PropertyStatus::Live ? 'Property went live' : 'Property submitted for go-live approval');

            return $status;
        });
    }
}
