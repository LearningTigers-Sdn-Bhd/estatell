<?php

namespace App\Actions\Facilities;

use App\Actions\Catalogues\UpdateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\FacilityBookedBy;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class UpdateFacility
{
    public const string REFUSAL_BOOKED_BY_LOCKED = 'booked_by_locked';

    public function __construct(private UpdateChargeItem $updateChargeItem, private CurrentBusinessDate $currentBusinessDate) {}

    /**
     * Save a facility and its charge item in one transaction. The charge item follows the name, the price (null is
     * "Charge by hand"), the taxes and whether the facility is on. Its code and description stay as they are. Without
     * `tax_ids` (the Available switch) the taxes stay too. Logs `facility.updated` with the changed fields, or nothing
     * when nothing changed. See docs/33-facilities/02-facility-settings.md.
     *
     * Returns null, and saves nothing, when `booked_by` changes while the facility has a booking from the business
     * date on that is not Cancelled (`booked_by_locked`): its quantity would then count something else.
     *
     * @param  array{name: string, booked_by: string, capacity: int, default_amount: ?string, rate_basis: string, active: bool, tax_ids?: ?list<int>, private_amount?: ?string}  $attributes
     */
    public function __invoke(User $actor, Facility $facility, array $attributes): ?Facility
    {
        return DB::transaction(function () use ($actor, $facility, $attributes): ?Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);

            if ($facility->booked_by->value !== $attributes['booked_by'] && $this->hasUpcomingBookings($facility)) {
                return null;
            }

            $chargeItem = ChargeItem::query()->with('transactionCode.applicableTaxes')->findOrFail($facility->charge_item_id);
            $amountBefore = $chargeItem->default_amount;
            $taxIdsBefore = collect($chargeItem->transactionCode->applicableTaxes->modelKeys())->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $taxIds = isset($attributes['tax_ids']) ? collect($attributes['tax_ids'])->sort()->values()->all() : $taxIdsBefore;

            $facility->fill([
                'name' => $attributes['name'],
                'booked_by' => $attributes['booked_by'],
                'capacity' => $attributes['capacity'],
                'rate_basis' => $attributes['rate_basis'],
                'private_amount' => $attributes['booked_by'] !== FacilityBookedBy::Person->value ? null : (array_key_exists('private_amount', $attributes) ? $attributes['private_amount'] : $facility->private_amount),
                'active' => $attributes['active'],
            ]);
            $changes = array_keys($facility->getDirty());

            ($this->updateChargeItem)($actor, $chargeItem, [
                'name' => $attributes['name'],
                'code' => $chargeItem->transactionCode->code,
                'category' => TransactionCategory::Facility,
                'description' => $chargeItem->description,
                'default_amount' => $attributes['default_amount'],
                'charging_unit' => ChargingUnit::PerItem,
                'allow_override' => true,
                'active' => $attributes['active'],
                'auto_add' => false,
                'tax_ids' => $taxIds,
            ]);

            if ($chargeItem->refresh()->default_amount !== $amountBefore) {
                $changes[] = 'default_amount';
            }

            if ($taxIds !== $taxIdsBefore) {
                $changes[] = 'tax_ids';
            }

            $facility->save();

            if ($changes !== []) {
                activity()
                    ->performedOn($facility)
                    ->causedBy($actor)
                    ->event('facility.updated')
                    ->withProperties(['property_id' => $facility->property_id, 'changed' => $changes])
                    ->log('Facility updated');
            }

            return $facility;
        });
    }

    private function hasUpcomingBookings(Facility $facility): bool
    {
        $businessDate = $this->currentBusinessDate->date($facility->property) ?? now()->toImmutable();

        return FacilityBooking::query()
            ->where('property_id', $facility->property_id)
            ->where('facility_id', $facility->id)
            ->live()
            ->whereDate('booking_date', '>=', $businessDate->toDateString())
            ->exists();
    }
}
