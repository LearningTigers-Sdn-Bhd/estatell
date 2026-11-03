<?php

namespace App\Actions\Reservations;

use App\Actions\Boats\ResyncBoatFares;
use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Boats\BoatFareAddOns;
use App\Services\Reservations\UnitPartyChangePreview;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ChangeUnitParty
{
    public function __construct(
        private UnitPartyChangePreview $preview,
        private ChangeNightPrice $changeNightPrice,
        private AddUnitAddOn $addUnitAddOn,
        private BoatFareAddOns $boatFareAddOns,
        private ResyncBoatFares $resyncBoatFares,
    ) {}

    /**
     * Change the adults and child ages of a Confirmed or Checked-in unit. The plan comes from
     * `UnitPartyChangePreview`: nights not charged yet take the new price, charged nights are corrected through
     * `ChangeNightPrice` only with `$repriceCharged`, and an overridden night keeps its price. The per-guest add-ons
     * not posted yet (a jetty fee) are written again for the new party, and the boat fares that are not charged yet
     * follow through `ResyncBoatFares`. Posted add-ons do not change. The unit row is locked first, and a refusal from
     * any step rolls everything back. See docs/12-reservations/14-change-guests.md.
     *
     * @param  list<int>  $childAges
     */
    public function __invoke(User $actor, ReservationUnit $unit, int $adults, array $childAges, bool $repriceCharged = false): UnitPartyResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $adults, $childAges, $repriceCharged): UnitPartyResult {
                $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
                $plan = $this->preview->plan($locked, $adults, $childAges, $repriceCharged);

                if ($plan->isRefused()) {
                    return UnitPartyResult::refused((string) $plan->refusal);
                }

                $previous = ['adults' => $locked->adults, 'child_ages' => $locked->childAges->pluck('age')->map(fn (mixed $age): int => (int) $age)->sort()->values()->all()];
                $previousTotal = $plan->before;

                $locked->forceFill(['adults' => $plan->adults, 'children' => count($plan->childAges)])->save();
                $locked->childAges()->delete();

                foreach ($plan->childAges as $age) {
                    $locked->childAges()->create(['age' => $age]);
                }

                $repriced = 0;
                $toCorrect = [];

                foreach ($plan->nights as $row) {
                    if (! $row['changes'] || $row['price'] === null) {
                        continue;
                    }

                    $repriced++;

                    if ($row['charged']) {
                        $toCorrect[(string) $row['price']->net()->getAmount()][] = $row['night'];

                        continue;
                    }

                    $row['night']->forceFill([
                        'base_amount' => (string) $row['price']->base->getAmount(),
                        'extra_amount' => (string) $row['price']->extra->getAmount(),
                        'supplement_amount' => (string) $row['price']->supplement->getAmount(),
                        'discount_amount' => (string) $row['price']->discountAmount->getAmount(),
                        'amount' => (string) $row['price']->net()->getAmount(),
                    ])->save();
                }

                $reason = __('Guests changed: :from to :to', ['from' => $this->partyLabel($previous['adults'], $previous['child_ages']), 'to' => $this->partyLabel($plan->adults, $plan->childAges)]);
                $corrected = 0;

                foreach ($toCorrect as $price => $nights) {
                    $result = ($this->changeNightPrice)($actor, $locked, $nights, (string) $price, $reason);

                    if ($result->isRefused()) {
                        throw new UnitPartyRefused((string) $result->refusal);
                    }

                    $corrected += $result->corrected;
                }

                $this->rewritePerGuestAddOns($actor, $locked->refresh());
                $boatRefusal = ($this->resyncBoatFares)($actor, $locked);

                if ($boatRefusal !== null) {
                    throw new UnitPartyRefused($boatRefusal);
                }

                activity()
                    ->performedOn($locked->reservation)
                    ->causedBy($actor)
                    ->event('reservation.party_changed')
                    ->withProperties([
                        'property_id' => $locked->property_id,
                        'reservation_unit_id' => $locked->id,
                        'previous_adults' => $previous['adults'],
                        'previous_child_ages' => $previous['child_ages'],
                        'adults' => $plan->adults,
                        'child_ages' => $plan->childAges,
                        'previous_total' => $previousTotal,
                        'total' => $plan->after,
                        'repriced_nights' => $repriced,
                        'corrected_nights' => $corrected,
                        'from_channel' => $plan->fromChannel,
                    ])
                    ->log('Unit guests changed');

                return UnitPartyResult::done($repriced, $corrected);
            });
        } catch (UnitPartyRefused $refused) {
            return UnitPartyResult::refused($refused->getMessage());
        }
    }

    /**
     * Remove the unposted rows of per-guest items (not boat fares) and add each item again, so the quantity and the
     * child rows follow the new party. The adult amount is kept when the item allows another amount.
     */
    private function rewritePerGuestAddOns(User $actor, ReservationUnit $unit): void
    {
        $fareIds = $this->boatFareAddOns->idsFor($unit);
        $rows = $unit->addOns()
            ->whereNull('folio_line_id')
            ->whereNotIn('id', $fareIds)
            ->with('chargeItem')
            ->orderBy('id')
            ->get()
            ->filter(fn (UnitAddOn $addOn): bool => in_array($addOn->chargeItem->charging_unit, [ChargingUnit::PerGuest, ChargingUnit::PerGuestNight], true));

        $groups = $rows->groupBy(fn (UnitAddOn $addOn): string => $addOn->charge_item_id.'|'.($addOn->chargeItem->charging_unit === ChargingUnit::PerGuest ? $addOn->service_date?->toDateString() : ''));

        foreach ($groups as $group) {
            $first = $group->first();
            $adultRow = $group->first(fn (UnitAddOn $addOn): bool => $addOn->property_age_group_id === null) ?? $first;
            $item = $first->chargeItem;
            $keepAmount = $item->allow_override && ($item->default_amount === null || ! BigDecimal::of($adultRow->unit_amount)->isEqualTo($item->default_amount));

            UnitAddOn::query()->whereKey($group->modelKeys())->delete();

            $result = ($this->addUnitAddOn)($actor, $unit, new NewAddOnData(
                chargeItemId: $item->id,
                unitAmount: $keepAmount ? $adultRow->unit_amount : null,
                serviceDate: $item->charging_unit === ChargingUnit::PerGuest ? $first->service_date : null,
            ));

            if ($result->isRefused()) {
                throw new UnitPartyRefused((string) $result->refusal);
            }
        }
    }

    /**
     * @param  list<int>  $childAges
     */
    private function partyLabel(int $adults, array $childAges): string
    {
        $label = trans_choice(':count adult|:count adults', $adults);

        return $childAges === [] ? $label : $label.', '.trans_choice(':count child|:count children', count($childAges)).' ('.implode(', ', $childAges).')';
    }
}
