<?php

namespace App\Actions\Platform;

use App\Actions\Property\IssuePropertyCode;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MovePropertyToPartner
{
    public function __construct(private IssuePropertyCode $issuePropertyCode) {}

    /**
     * Move a direct property to an Active partner, or a partner's property back to direct, and log it. The
     * property gets a new code from the new owner's prefix (ES for direct), so the old code stops working. Returns false for a partner-to-partner move or an inactive target partner. Moving to where the
     * property already is does nothing.
     */
    public function __invoke(User $admin, Property $property, ?Partner $partner): bool
    {
        return DB::transaction(function () use ($admin, $property, $partner): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $oldPartner = $property->partner;

            if ($oldPartner?->id === $partner?->id) {
                return true;
            }

            if ($oldPartner !== null && $partner !== null) {
                return false;
            }

            if ($partner !== null) {
                $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);

                if ($partner->status !== PartnerStatus::Active) {
                    return false;
                }
            }

            $oldCode = $property->code;

            $property->forceFill([
                'partner_id' => $partner?->id,
                'code' => ($this->issuePropertyCode)($partner?->code_prefix ?? 'ES'),
            ])->save();

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event('property.partner-moved')
                ->withProperties([
                    'from' => $oldPartner === null ? null : ['id' => $oldPartner->id, 'name' => $oldPartner->name],
                    'to' => $partner === null ? null : ['id' => $partner->id, 'name' => $partner->name],
                    'code' => ['from' => $oldCode, 'to' => $property->code],
                ])
                ->log($partner === null ? 'Property moved to direct' : 'Property moved to a partner');

            return true;
        });
    }
}
