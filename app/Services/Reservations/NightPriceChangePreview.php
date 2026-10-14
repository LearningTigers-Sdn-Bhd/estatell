<?php

namespace App\Services\Reservations;

use App\Actions\Folio\PostingResult;
use App\Actions\FrontDesk\UnitStayResult;
use App\Actions\Reservations\NightPriceResult;
use App\Data\Reservations\NightPriceChangePreviewData;
use App\Data\Reservations\NightPriceNightData;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\NightChargeQuote;
use App\Services\Folio\NightQuote;
use App\Services\Folio\QuotedCharge;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Works out what Change price does to the ticked nights of a unit, for `ChangeNightPrice` to write and for its Dialog
 * to show, so the two never differ. It reads and writes nothing. A Scheduled night only gets the new price. A charged
 * night (its `night:{id}` line exists) reverses its live lines and posts the night again at the new price under
 * `night:{id}:fix:{n}`, routed with today's rules. The difference is what the guest pays after the change minus
 * before, with tax. See docs/12-reservations/09-change-night-price.md.
 */
class NightPriceChangePreview
{
    private const PRICE_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private NightChargeQuote $nightChargeQuote,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private UnitNationality $unitNationality,
        private ReservationRefusals $refusals,
    ) {}

    /**
     * The checks of `ChangeNightPrice` after the reason, in its order, and the plan of each night that changes.
     * `ChangeNightPrice` calls it with the unit and the folios of the reservation locked.
     *
     * @param  list<int>  $nightIds
     */
    public function plan(ReservationUnit $unit, array $nightIds, string $price): NightPricePlan
    {
        if (! in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
            return NightPricePlan::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
        }

        $ids = array_values(array_unique($nightIds));
        $nights = $ids === [] ? new EloquentCollection : $unit->nights()->whereIn('id', $ids)->with('ratePlan.inclusions.chargeItem.transactionCode')->orderBy('date')->get();

        if ($nights->isEmpty() || $nights->count() !== count($ids)) {
            return NightPricePlan::refused(NightPriceResult::REFUSAL_NIGHTS_INVALID);
        }

        if (preg_match(self::PRICE_PATTERN, $price) !== 1) {
            return NightPricePlan::refused(NightPriceResult::REFUSAL_PRICE_INVALID);
        }

        $property = $unit->property;
        $currency = $property->settings->currency->getCurrencyCode();
        $newPrice = Money::of($price, $currency);
        $unit->loadMissing('discount', 'reservation');
        $changing = $nights->reject(fn (UnitNight $night): bool => $newPrice->isEqualTo($night->amount) && ! $this->hasDiscount($unit, $night))->values();

        if ($changing->isEmpty()) {
            return NightPricePlan::planned([], null, Money::zero($currency));
        }

        $charged = FolioLine::query()->whereIn('idempotency_key', $changing->map(fn (UnitNight $night): string => 'night:'.$night->id)->all())->pluck('idempotency_key')->flip();
        $businessDate = null;

        if ($charged->isNotEmpty()) {
            $businessDate = $this->currentBusinessDate->date($property)?->toDateString();

            if ($businessDate === null) {
                return NightPricePlan::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }
        }

        $liveLines = $this->liveLines($changing->filter(fn (UnitNight $night): bool => $charged->has('night:'.$night->id)));
        $folios = Folio::query()->whereIn('id', $liveLines->pluck('folio_id')->unique()->all())->orderBy('id')->get();

        foreach ($folios as $folio) {
            if ($folio->status !== FolioStatus::Open) {
                return NightPricePlan::refused((string) $this->folioPostability->refusalFor($folio));
            }
        }

        $roomCode = TransactionCode::query()->where('property_id', $unit->property_id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->first();

        if ($roomCode === null) {
            return NightPricePlan::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
        }

        $taxes = $this->nightChargeQuote->propertyTaxes($property);
        $nationality = $this->unitNationality->for($unit);
        $firstDate = $unit->nights()->min('date');
        $planned = [];
        $difference = Money::zero($currency);

        foreach ($changing as $night) {
            $isCharged = $charged->has('night:'.$night->id);
            $first = $night->date->toDateString() === substr((string) $firstDate, 0, 10);
            $repriced = clone $night;
            $repriced->override_amount = (string) $newPrice->getAmount();
            $key = $isCharged ? 'night:'.$night->id.':fix:'.$this->nextFix($night) : null;
            $quote = $this->nightChargeQuote->quote($property, $taxes, $unit, $repriced, $first, $roomCode, $nationality, $key);

            if ($quote->isRefused()) {
                return NightPricePlan::refused((string) $quote->refusal);
            }

            if ($quote->taxRefusal() !== null) {
                return NightPricePlan::refused((string) $quote->taxRefusal());
            }

            $targets = [];

            if ($isCharged) {
                foreach ($quote->charges as $charge) {
                    $folio = $this->folioRouter->targetFor($unit, $charge->code);

                    if ($folio !== null && $folio->status !== FolioStatus::Open) {
                        return NightPricePlan::refused((string) $this->folioPostability->refusalFor($folio));
                    }

                    $targets[$charge->key] = $folio;
                }
            }

            $lines = array_values($liveLines->where('source_id', $night->id)->all());
            $oldTotal = $isCharged
                ? array_reduce($lines, fn (Money $sum, FolioLine $line): Money => $sum->plus($this->treeTotal($line, $currency)), Money::zero($currency))
                : $this->scheduledTotal($property, $taxes, $unit, $night, $first, $roomCode, $nationality, $currency);
            $newTotal = $this->quoteTotal($quote, $currency);
            $difference = $difference->plus($newTotal)->minus($oldTotal);
            $planned[] = new PlannedNightPrice($night, $isCharged, $this->hasDiscount($unit, $night), $lines, $quote, $key, $targets, $oldTotal, $newTotal);
        }

        return NightPricePlan::planned($planned, $businessDate, $difference);
    }

    /**
     * What the Dialog shows: every night of the unit with its price and whether it is charged, the current price of
     * the ticked nights, and with a price typed, the difference or the refusal. Nothing is refused for a missing price
     * or reason: the form asks for them.
     *
     * @param  list<int>  $nightIds
     */
    public function preview(ReservationUnit $unit, array $nightIds, ?string $price): NightPriceChangePreviewData
    {
        $nights = $unit->nights()->orderBy('date')->get();
        $chargedKeys = FolioLine::query()->whereIn('idempotency_key', $nights->map(fn (UnitNight $night): string => 'night:'.$night->id)->all())->pluck('idempotency_key')->flip();
        $rows = array_values($nights->map(fn (UnitNight $night): NightPriceNightData => new NightPriceNightData($night->id, $night->date->toDateString(), $night->amount, $chargedKeys->has('night:'.$night->id)))->all());
        $ticked = $nights->whereIn('id', $nightIds);
        $hadDiscount = $ticked->contains(fn (UnitNight $night): bool => $this->hasDiscount($unit, $night));
        $amounts = $ticked->pluck('amount')->sort(fn (string $a, string $b): int => BigDecimal::of($a)->compareTo($b))->values();
        $was = $amounts->first();
        $wasHigh = $amounts->isEmpty() || BigDecimal::of($amounts->first())->isEqualTo($amounts->last()) ? null : $amounts->last();

        if ($price === null || $price === '' || $ticked->isEmpty()) {
            return new NightPriceChangePreviewData($rows, $was, $wasHigh, $hadDiscount, [], [], null, null);
        }

        $plan = $this->plan($unit, array_values(array_map(intval(...), $ticked->modelKeys())), $price);

        if ($plan->isRefused()) {
            return new NightPriceChangePreviewData($rows, $was, $wasHigh, $hadDiscount, [], [], null, $this->refusals->message((string) $plan->refusal));
        }

        $dates = fn (bool $charged): array => array_values(array_map(
            fn (PlannedNightPrice $night): string => $night->night->date->toDateString(),
            array_filter($plan->nights, fn (PlannedNightPrice $night): bool => $night->charged === $charged),
        ));

        return new NightPriceChangePreviewData($rows, $was, $wasHigh, $hadDiscount, $dates(true), $dates(false), (string) $plan->difference?->getAmount(), null);
    }

    /**
     * An overridden night has no discount lines, because the override replaces the whole sum.
     */
    private function hasDiscount(ReservationUnit $unit, UnitNight $night): bool
    {
        return $night->override_amount === null && ((float) $night->discount_amount > 0 || $unit->discount_id !== null);
    }

    /**
     * The top-level lines of the nights that are not reversed and not a reversal: the room, the inclusions, an earlier
     * correction, and copies moved to another folio (a move keeps the source).
     *
     * @param  EloquentCollection<int, UnitNight>  $nights
     * @return Collection<int, FolioLine>
     */
    private function liveLines(Collection $nights): Collection
    {
        if ($nights->isEmpty()) {
            return collect();
        }

        return FolioLine::query()
            ->where('source_type', (new UnitNight)->getMorphClass())
            ->whereIn('source_id', $nights->modelKeys())
            ->whereNull('parent_line_id')
            ->whereNull('reversal_of_id')
            ->where('kind', '<>', TransactionKind::Payment)
            ->whereDoesntHave('reversal')
            ->orderBy('id')
            ->get();
    }

    /**
     * The next correction number of a night: one more than the highest `night:{id}:fix:{n}` room line.
     */
    private function nextFix(UnitNight $night): int
    {
        $prefix = 'night:'.$night->id.':fix:';

        return FolioLine::query()
            ->where('idempotency_key', 'like', $prefix.'%')
            ->pluck('idempotency_key')
            ->map(fn (string $key): string => substr($key, strlen($prefix)))
            ->filter(fn (string $rest): bool => ctype_digit($rest))
            ->map(fn (string $rest): int => (int) $rest)
            ->max() + 1;
    }

    /**
     * A line with its descendants that are not reversed: what it adds to the folio now.
     */
    private function treeTotal(FolioLine $line, string $currency): Money
    {
        $total = Money::of($line->amount, $currency);

        foreach ($line->children()->whereDoesntHave('reversal')->get() as $child) {
            $total = $total->plus($this->treeTotal($child, $currency));
        }

        return $total;
    }

    /**
     * What a Scheduled night will post at its current price. A night that cannot be quoted counts its amount.
     *
     * @param  Collection<int, Tax>  $taxes
     */
    private function scheduledTotal(Property $property, Collection $taxes, ReservationUnit $unit, UnitNight $night, bool $first, TransactionCode $roomCode, ?string $nationality, string $currency): Money
    {
        $quote = $this->nightChargeQuote->quote($property, $taxes, $unit, $night, $first, $roomCode, $nationality);

        return $quote->isRefused() || $quote->taxRefusal() !== null ? Money::of($night->amount, $currency) : $this->quoteTotal($quote, $currency);
    }

    /**
     * The folio total of a quote: each charge line, minus its discounts, plus its tax lines.
     */
    private function quoteTotal(NightQuote $quote, string $currency): Money
    {
        return array_reduce($quote->charges, function (Money $sum, QuotedCharge $charge): Money {
            $sum = $sum->plus($charge->lineAmount);

            foreach ($charge->discounts as $discount) {
                $sum = $sum->minus($discount->amount);
            }

            foreach ($charge->tax->taxes as $component) {
                $sum = $sum->plus($component->amount);
            }

            return $sum;
        }, Money::zero($currency));
    }
}
