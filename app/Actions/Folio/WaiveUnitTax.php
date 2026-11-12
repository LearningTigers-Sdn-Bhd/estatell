<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\TaxWaiverReason;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\Tax;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WaiveUnitTax
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CreateManualBatch $createManualBatch,
        private ReverseLine $reverseLine,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Stop charging the chosen taxes for one unit of a Confirmed, Checked-in or Checked-out stay, because someone
     * else collected them (`paid_to`) or the guest has a receipt. One waiver row per tax. The live tax lines already
     * posted on the unit's nights (and its day-use block) are reversed on the business date, in one manual batch per
     * folio whose reason names the payer and the reference. Nights not posted yet are quoted without the tax
     * (`TaxWaivers`). The unit row is locked first and every check runs before the first write, so a refusal writes
     * and logs nothing. A tax already waived for the unit refuses the whole request (`already_waived`), which also
     * stops a repeated click. Permissions are checked by the route. See docs/06-catalogues/03-taxes.md#tax-waivers.
     *
     * @param  list<int>  $taxIds
     */
    public function __invoke(User $actor, ReservationUnit $unit, array $taxIds, TaxWaiverReason $reason, ?string $paidTo, string $reference): TaxWaiverResult
    {
        return DB::transaction(function () use ($actor, $unit, $taxIds, $reason, $paidTo, $reference): TaxWaiverResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
                return TaxWaiverResult::refused(TaxWaiverResult::REFUSAL_UNIT_NOT_WAIVABLE);
            }

            $taxIds = array_values(array_unique($taxIds));
            $taxes = Tax::query()->where('property_id', $locked->property_id)->whereIn('id', $taxIds)->orderBy('id')->get();

            if ($taxIds === [] || $taxes->count() !== count($taxIds)) {
                return TaxWaiverResult::refused(TaxWaiverResult::REFUSAL_TAX_NOT_FOUND);
            }

            if ($locked->taxWaivers()->whereIn('tax_id', $taxIds)->exists()) {
                return TaxWaiverResult::refused(TaxWaiverResult::REFUSAL_ALREADY_WAIVED);
            }

            $lines = $this->liveTaxLines($locked, $taxes);
            $folios = Folio::query()->whereIn('id', $lines->pluck('folio_id')->unique()->all())->orderBy('id')->lockForUpdate()->get();

            if ($folios->contains(fn (Folio $folio): bool => $folio->status !== FolioStatus::Open)) {
                return TaxWaiverResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $businessDate = $lines->isEmpty() ? null : $this->currentBusinessDate->date($locked->property);

            if ($lines->isNotEmpty() && $businessDate === null) {
                return TaxWaiverResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $paidTo = $reason->needsPaidTo() ? trim((string) $paidTo) : null;
            $reference = trim($reference);
            $waivers = $taxes->map(function (Tax $tax) use ($actor, $locked, $reason, $paidTo, $reference): ReservationUnitTaxWaiver {
                $waiver = new ReservationUnitTaxWaiver(['tax_id' => $tax->id, 'reason' => $reason, 'paid_to' => $paidTo, 'reference' => $reference]);
                $waiver->property_id = $locked->property_id;
                $waiver->reservation_unit_id = $locked->id;
                $waiver->user_id = $actor->id;
                $waiver->save();

                return $waiver;
            });
            $batchReason = $reason->needsPaidTo()
                ? __('Tax paid by :payer · :reference', ['payer' => $paidTo, 'reference' => $reference])
                : __('Tax paid by guest · receipt :reference', ['reference' => $reference]);

            foreach ($folios as $folio) {
                $batch = ($this->createManualBatch)($actor, $folio, $batchReason, 'tax-waiver:'.$waivers->first()->id.':folio:'.$folio->id);

                foreach ($lines->where('folio_id', $folio->id) as $line) {
                    $this->reverseLine->post($actor, $line, $businessDate->toDateString(), null, $batch->id);
                }

                ($this->closeSettledUnitFolio)($actor, $folio);
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_tax_waived')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'tax_ids' => $taxes->pluck('id')->all(),
                    'reason' => $reason->value,
                    'paid_to' => $paidTo,
                    'reference' => $reference,
                    'reversed_line_ids' => $lines->pluck('id')->all(),
                ])
                ->log('Unit tax waived');

            return TaxWaiverResult::done($lines->count());
        });
    }

    /**
     * The live tax lines of the chosen taxes on the unit's stay charges: the charges of its nights (room, inclusions
     * and Change price corrections) and of its day-use block. Add-ons and fees keep their tax.
     *
     * @param  Collection<int, Tax>  $taxes
     * @return Collection<int, FolioLine>
     */
    private function liveTaxLines(ReservationUnit $unit, Collection $taxes): Collection
    {
        $nightIds = $unit->nights()->pluck('id')->all();

        return FolioLine::query()
            ->where('kind', TransactionKind::Tax)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->whereIn('transaction_code_id', $taxes->pluck('transaction_code_id')->all())
            ->whereHas('parent', fn (Builder $parent) => $parent->where(fn (Builder $sources) => $sources
                ->where(fn (Builder $nights) => $nights->where('source_type', (new UnitNight)->getMorphClass())->whereIn('source_id', $nightIds))
                ->orWhere(fn (Builder $dayUse) => $dayUse->where('source_type', $unit->getMorphClass())->where('source_id', $unit->id)->where('idempotency_key', 'like', 'dayuse:%'))))
            ->orderBy('id')
            ->get();
    }
}
