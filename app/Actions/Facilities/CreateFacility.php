<?php

namespace App\Actions\Facilities;

use App\Actions\Catalogues\CreateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\FacilityBookedBy;
use App\Enums\TransactionCategory;
use App\Models\Facility;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\DerivedTransactionCode;
use Illuminate\Support\Facades\DB;

class CreateFacility
{
    public function __construct(
        private CreateChargeItem $createChargeItem,
        private DerivedTransactionCode $derivedCode,
    ) {}

    /**
     * Add a facility and its own charge item in one transaction, at the end of the list. The charge item takes the
     * facility's name, a code derived from it and the Facility category. It is priced per item, staff can change the
     * price, and it is never added to bookings on its own. A null `default_amount` is "Charge by hand". Logs
     * `facility.created`. See docs/33-facilities/02-facility-settings.md.
     *
     * @param  array{name: string, booked_by: string, capacity: int, default_amount: ?string, rate_basis: string, active: bool, tax_ids?: ?list<int>, private_amount?: ?string}  $attributes
     */
    public function __invoke(Property $property, User $actor, array $attributes): Facility
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Facility {
            $chargeItem = ($this->createChargeItem)($property, $actor, [
                'name' => $attributes['name'],
                'code' => $this->derivedCode->forName($property, $attributes['name'], 'FACILITY'),
                'category' => TransactionCategory::Facility,
                'default_amount' => $attributes['default_amount'],
                'charging_unit' => ChargingUnit::PerItem,
                'allow_override' => true,
                'auto_add' => false,
                'active' => $attributes['active'],
                'tax_ids' => $attributes['tax_ids'] ?? [],
            ]);

            $facility = new Facility([
                'name' => $attributes['name'],
                'booked_by' => $attributes['booked_by'],
                'capacity' => $attributes['capacity'],
                'rate_basis' => $attributes['rate_basis'],
                'private_amount' => $attributes['booked_by'] === FacilityBookedBy::Person->value ? ($attributes['private_amount'] ?? null) : null,
                'active' => $attributes['active'],
            ]);
            $facility->property_id = $property->id;
            $facility->charge_item_id = $chargeItem->id;
            $facility->position = ((int) $property->facilities()->max('position')) + 1;
            $facility->save();

            activity()
                ->performedOn($facility)
                ->causedBy($actor)
                ->event('facility.created')
                ->withProperties(['property_id' => $property->id, 'name' => $facility->name])
                ->log('Facility created');

            return $facility;
        });
    }
}
