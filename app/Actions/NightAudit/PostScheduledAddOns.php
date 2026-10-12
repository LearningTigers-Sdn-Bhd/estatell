<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\PostingResult;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\AddOnLabel;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostScheduledAddOns
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private TaxCalculator $taxCalculator,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * Post every add-on of the property whose `service_date` is the current business date and that has no posted line,
     * on a CheckedIn unit. Each add-on is its own transaction with its unit row locked, so one add-on that cannot post
     * (a missing nationality for a foreign-only tax, a closed folio) never stops the others. The row gives the item,
     * quantity and amount: nothing from the caller is trusted. The key is `addon:{id}`, the add-on is the line's source,
     * and `folio_line_id` is set to the charge line. A null actor is the night audit. The results are keyed by add-on
     * id, so the audit can turn a refusal into an exception row. A repeat returns the existing lines as replayed.
     * Add-ons of a Cancelled, NoShow or CheckedOut unit stay as history. See docs/13-folio/02-posting-charges.md.
     *
     * @return array<int, PostingResult>
     */
    public function __invoke(?User $actor, Property $property, CarbonImmutable $date): array
    {
        $businessDate = $this->currentBusinessDate->date($property);

        if ($businessDate === null || ! $businessDate->isSameDay($date)) {
            return [];
        }

        $addOns = UnitAddOn::query()
            ->whereDate('service_date', $date->toDateString())
            ->whereNull('folio_line_id')
            ->whereHas('reservationUnit', fn ($units) => $units->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn))
            ->orderBy('id')
            ->pluck('id');
        $results = [];

        foreach ($addOns as $id) {
            $results[$id] = $this->post($actor, $property, $id, $businessDate);
        }

        return $results;
    }

    private function post(?User $actor, Property $property, int $addOnId, CarbonImmutable $businessDate): PostingResult
    {
        return DB::transaction(function () use ($actor, $property, $addOnId, $businessDate): PostingResult {
            $unitId = UnitAddOn::query()->whereKey($addOnId)->value('reservation_unit_id');
            $unit = $unitId === null ? null : ReservationUnit::query()->lockForUpdate()->find($unitId);
            $addOn = $unit === null ? null : UnitAddOn::query()->with(['chargeItem.transactionCode', 'ageGroup'])->find($addOnId);

            if ($addOn === null || $unit === null || $addOn->folio_line_id !== null || $unit->status !== UnitStatus::CheckedIn || $unit->property_id !== $property->id) {
                return PostingResult::refused(PostingResult::REFUSAL_UNIT_NOT_POSTABLE);
            }

            $key = 'addon:'.$addOn->id;
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
            }

            $code = $addOn->chargeItem->transactionCode;

            if ($code->kind !== TransactionKind::Charge) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $unitAmount = Money::of($addOn->unit_amount, $property->settings->currency->value);
            $tax = $this->taxCalculator->calculate($property, $code, $unitAmount->multipliedBy($addOn->quantity), (string) $addOn->quantity, $this->unitNationality->for($unit));

            if (! $tax->isCalculated()) {
                return PostingResult::refused((string) $tax->refusalReason);
            }

            $folio = $this->folioRouter->targetFor($unit, $code);

            if ($folio !== null && $folio->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
            }

            $folio ??= ($this->createFolio)($unit->reservation, $unit);
            $lines = ($this->postFolioCharge)($actor, $folio, $code, AddOnLabel::for($addOn), $addOn->quantity, $unitAmount, $tax, $key, $businessDate->toDateString(), $addOn);
            $addOn->folio_line_id = $lines[0]->id;
            $addOn->save();

            return PostingResult::posted($lines);
        });
    }
}
