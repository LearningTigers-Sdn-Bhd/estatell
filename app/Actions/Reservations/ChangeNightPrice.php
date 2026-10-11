<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\CloseSettledUnitFolio;
use App\Actions\Folio\PostNightLines;
use App\Actions\Folio\ReverseLine;
use App\Actions\FrontDesk\UnitStayResult;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reservations\NightPriceChangePreview;
use App\Services\Reservations\NightPricePlan;
use App\Services\Reservations\PlannedNightPrice;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ChangeNightPrice
{
    public function __construct(
        private NightPriceChangePreview $nightPriceChangePreview,
        private ReverseLine $reverseLine,
        private PostNightLines $postNightLines,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Set one price on the ticked nights of a unit. A Scheduled night gets `override_amount` and the night audit
     * charges it. A charged night also reverses every live line of the night (`ReverseLine::post()`, children with
     * their parent) and posts the night again on the business date under `night:{id}:fix:{n}` through
     * `PostNightLines`, so the audit, which finds `night:{id}`, never charges it twice. A night at the same price with
     * no discount is skipped. The unit row and the folios of the reservation are locked first, in id order, and every
     * check (`NightPriceChangePreview::plan()`) runs before the first write, so a refusal writes and logs nothing. The
     * route checks `reservations.change-price`. See docs/12-reservations/09-change-night-price.md.
     *
     * @param  list<UnitNight>  $nights
     */
    public function __invoke(User $actor, ReservationUnit $unit, array $nights, string $price, string $reason): NightPriceResult
    {
        return DB::transaction(function () use ($actor, $unit, $nights, $price, $reason): NightPriceResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            Folio::query()->where('reservation_id', $locked->reservation_id)->orderBy('id')->lockForUpdate()->get();

            if (trim($reason) === '') {
                return NightPriceResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $plan = $this->nightPriceChangePreview->plan($locked, array_map(fn (UnitNight $night): int => $night->id, $nights), $price);

            if ($plan->isRefused()) {
                return NightPriceResult::refused((string) $plan->refusal);
            }

            if ($plan->nights === []) {
                return NightPriceResult::done(0, 0);
            }

            $amount = (string) BigDecimal::of($price)->toScale(2);
            $oldAmounts = [];
            $touched = [];

            foreach ($plan->nights as $planned) {
                $oldAmounts[$planned->night->id] = $planned->night->amount;
                $planned->night->forceFill(['override_amount' => $amount, 'amount' => $amount])->save();

                if (! $planned->charged) {
                    continue;
                }

                foreach ($planned->liveLines as $line) {
                    $this->reverseLine->post($actor, $line, (string) $plan->businessDate, null);
                    $touched[$line->folio_id] = true;
                }

                $lines = ($this->postNightLines)(
                    $actor,
                    $locked,
                    $planned->night,
                    $planned->quote,
                    $planned->targets,
                    (string) $plan->businessDate,
                    null,
                    __(':description · night of :date, corrected', ['description' => $planned->quote->charges[0]->description, 'date' => $planned->night->date->translatedFormat('j M')]),
                );

                foreach ($lines as $line) {
                    $touched[$line->folio_id] = true;
                }
            }

            $this->log($actor, $locked, $plan, $oldAmounts, $amount, trim($reason));

            if ($locked->status === UnitStatus::CheckedOut) {
                Folio::query()->whereIn('id', array_keys($touched))->orderBy('id')->get()->each(fn (Folio $folio) => ($this->closeSettledUnitFolio)($actor, $folio));
            }

            $corrected = count(array_filter($plan->nights, fn (PlannedNightPrice $planned): bool => $planned->charged));

            return NightPriceResult::done(count($plan->nights), $corrected);
        });
    }

    /**
     * @param  array<int, string>  $oldAmounts  By unit night id
     */
    private function log(User $actor, ReservationUnit $unit, NightPricePlan $plan, array $oldAmounts, string $amount, string $reason): void
    {
        activity()
            ->performedOn($unit->reservation)
            ->causedBy($actor)
            ->event('reservation.night_price_changed')
            ->withProperties([
                'property_id' => $unit->property_id,
                'reservation_unit_id' => $unit->id,
                'nights' => array_map(fn (PlannedNightPrice $planned): array => [
                    'unit_night_id' => $planned->night->id,
                    'date' => $planned->night->date->toDateString(),
                    'old_amount' => $oldAmounts[$planned->night->id],
                    'new_amount' => $amount,
                    'charged' => $planned->charged,
                    'fix_key' => $planned->key,
                ], $plan->nights),
                'difference' => (string) $plan->difference?->getAmount(),
                'reason' => $reason,
                'business_date' => $plan->businessDate,
            ])
            ->log('Night price changed');
    }
}
