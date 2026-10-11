<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\DayUseChargeQuote;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\NightChargeQuote;
use App\Services\Reservations\UnitNationality;

class PostDayUseBlock
{
    public function __construct(
        private DayUseChargeQuote $dayUseChargeQuote,
        private NightChargeQuote $nightChargeQuote,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * Post the block of a day use unit on `$postingDate` (the business date): the DAY_USE line under the key
     * `dayuse:{unit_id}` with the description "Day use · 10:00–14:00", each plan inclusion under
     * `dayuse:{unit_id}:incl:{inclusion_id}`, and the tax lines of every charge. The lines come from
     * `DayUseChargeQuote`, the quote the folio page shows as the Scheduled row, and each charge goes to the folio
     * `FolioRouter` chooses for its code, like a night. The unit is the source of every line. The caller runs this
     * inside its transaction (`CheckInUnit`, which locks the unit). Every check runs before the first write, so a
     * refusal writes nothing. A block already posted returns its lines as replayed. See docs/12-reservations/10-day-use.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $postingDate): PostingResult
    {
        $existing = FolioLine::query()->where('idempotency_key', 'dayuse:'.$unit->id)->first();

        if ($existing !== null) {
            return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
        }

        $dayUse = $unit->dayUse()->first();
        $code = TransactionCode::query()->where('property_id', $unit->property_id)->where('system_key', SystemTransactionCode::DayUseRevenue->value)->first();

        if ($dayUse === null || $code === null) {
            return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
        }

        $property = $unit->property;
        $unit->load('ratePlan.inclusions.chargeItem.transactionCode');
        $quote = $this->dayUseChargeQuote->quote($property, $this->nightChargeQuote->propertyTaxes($property), $unit, $dayUse, $code, $this->unitNationality->for($unit));

        if ($quote->isRefused()) {
            return PostingResult::refused((string) $quote->refusal);
        }

        if ($quote->taxRefusal() !== null) {
            return PostingResult::refused((string) $quote->taxRefusal());
        }

        $targets = [];

        foreach ($quote->charges as $charge) {
            $folio = $this->folioRouter->targetFor($unit, $charge->code);

            if ($folio !== null && $folio->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
            }

            $targets[$charge->key] = $folio;
        }

        $unitFolio = null;
        $lines = [];

        foreach ($quote->charges as $charge) {
            $folio = $targets[$charge->key] ?? $unitFolio ??= ($this->createFolio)($unit->reservation, $unit);
            array_push($lines, ...($this->postFolioCharge)(
                $actor,
                $folio,
                $charge->code,
                $charge->description,
                1,
                $charge->unitAmount,
                $charge->tax,
                $charge->key,
                $postingDate,
                $unit,
                $charge->lineAmount,
            ));
        }

        return PostingResult::posted($lines);
    }
}
