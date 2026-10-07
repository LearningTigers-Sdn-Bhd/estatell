<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\PostingResult;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\NightChargeQuote;
use App\Services\Folio\QuotedDiscount;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\UnitNationality;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostRoomCharges
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private NightChargeQuote $nightChargeQuote,
        private FolioRouter $folioRouter,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * Post one night of one CheckedIn unit on the current business date: the room line (key `night:{unit_night_id}`,
     * the unit night as its source), its stay and booking discounts, each inclusion, and the tax of every charge. The
     * lines come from `NightChargeQuote`, the same quote the folio page shows as Scheduled rows, and each charge goes
     * to the folio `FolioRouter` chooses for its code. A null actor is the night audit. The unit row is locked first, so
     * two runs never race. Every check runs before the first write, so a refusal writes nothing. A night whose room line
     * already exists returns it as replayed and writes nothing. Automatic postings log no activity: the lines are the
     * record. See docs/13-folio/02-posting-charges.md.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, CarbonImmutable $date): PostingResult
    {
        return DB::transaction(function () use ($actor, $unit, $date): PostingResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $property = $locked->property;
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null || ! $businessDate->isSameDay($date)) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($locked->status !== UnitStatus::CheckedIn) {
                return PostingResult::refused(PostingResult::REFUSAL_UNIT_NOT_POSTABLE);
            }

            $night = $locked->nights()->whereDate('date', $date->toDateString())->with('ratePlan.inclusions.chargeItem.transactionCode')->first();

            if ($night === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NIGHT_NOT_FOUND);
            }

            $existing = FolioLine::query()->where('idempotency_key', 'night:'.$night->id)->first();

            if ($existing !== null) {
                return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
            }

            $roomCode = $this->systemCode($locked, SystemTransactionCode::RoomRevenue);

            if ($roomCode === null) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $locked->loadMissing('discount', 'reservation');
            $firstNight = ! $locked->nights()->where('date', '<', $date->toDateString())->exists();
            $quote = $this->nightChargeQuote->quote($property, $this->nightChargeQuote->propertyTaxes($property), $locked, $night, $firstNight, $roomCode, $this->unitNationality->for($locked));

            if ($quote->isRefused()) {
                return PostingResult::refused((string) $quote->refusal);
            }

            if ($quote->taxRefusal() !== null) {
                return PostingResult::refused((string) $quote->taxRefusal());
            }

            $stayDiscountCode = $this->systemCode($locked, SystemTransactionCode::Discount);
            $needsStayDiscountCode = collect($quote->charges)->flatMap(fn ($charge): array => $charge->discounts)->contains(fn (QuotedDiscount $discount): bool => $discount->transactionCodeId === null);

            if ($needsStayDiscountCode && $stayDiscountCode === null) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $targets = [];

            foreach ($quote->charges as $charge) {
                $folio = $this->folioRouter->targetFor($locked, $charge->code);

                if ($folio !== null && $folio->status !== FolioStatus::Open) {
                    return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
                }

                $targets[$charge->key] = $folio;
            }

            $unitFolio = null;
            $result = [];

            foreach ($quote->charges as $charge) {
                $folio = $targets[$charge->key] ?? $unitFolio ??= ($this->createFolio)($locked->reservation, $locked);
                $discounts = array_map(
                    fn (QuotedDiscount $discount): QuotedDiscount => new QuotedDiscount($discount->key, $discount->description, $discount->amount, $discount->transactionCodeId ?? $stayDiscountCode?->id),
                    $charge->discounts,
                );
                $lines = ($this->postFolioCharge)(
                    $actor,
                    $folio,
                    $charge->code,
                    $charge->description,
                    1,
                    $charge->unitAmount,
                    $charge->tax,
                    $charge->key,
                    $businessDate->toDateString(),
                    $charge->key === 'night:'.$night->id ? $night : null,
                    $charge->lineAmount,
                    $discounts,
                );
                array_push($result, ...$lines);
            }

            return PostingResult::posted($result);
        });
    }

    private function systemCode(ReservationUnit $unit, SystemTransactionCode $key): ?TransactionCode
    {
        return TransactionCode::query()->where('property_id', $unit->property_id)->where('system_key', $key->value)->first();
    }
}
