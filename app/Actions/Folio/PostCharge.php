<?php

namespace App\Actions\Folio;

use App\Data\Folio\NewChargeData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\ServiceDateRange;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\AddOnLabel;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostCharge
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private TaxCalculator $taxCalculator,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private UnitNationality $unitNationality,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
        private ServiceDateRange $serviceDateRange,
    ) {}

    /**
     * Post one charge on a unit's folio, then one Tax child line for each tax component. The unit row is locked first,
     * so two postings of one unit never race. Every check runs before the first write, so a refusal writes and logs
     * nothing. The folio is the given `folio_id` (it must belong to the reservation, and a Unit folio must be the unit's own: `unit_not_on_folio`) or, with `ownFolio`, the unit's own folio (created when missing), or, without either, comes from `FolioRouter` (a routing rule or the unit's own folio), and a closed one refuses the posting with
     * no fallback (`folio_settled_to_city_ledger` when it is settled to the city ledger). `PostFolioCharge` writes the lines, and tax lines go on the folio of their charge. A key that already exists returns the existing lines and writes nothing, but only when the line's folio belongs to the unit's reservation (otherwise `charge_not_found`). The charge line holds the net
     * amount (the entered amount without inclusive tax), so the folio total is the gross amount.
     * The manual folio page also gives `service_date` (the day it really happened, inside `ServiceDateRange`, else `service_date_invalid`; the posting date stays the business date), `tax_ids` (taxes calculated in place of the item's own, and a tax that is not the property's refuses with `tax_not_available`) and `manual_batch_id`.
     * The last step closes the folio when it is an Open Unit folio of a CheckedOut unit at 0 (`CloseSettledUnitFolio`).
     * See docs/13-folio/02-posting-charges.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, NewChargeData $data): PostingResult
    {
        return DB::transaction(function () use ($actor, $unit, $data): PostingResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $key = $data->unitAddOnId === null ? $data->idempotencyKey ?? 'manual:'.Str::uuid() : 'addon:'.$data->unitAddOnId;
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                if ($existing->folio->reservation_id !== $locked->reservation_id) {
                    return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
                }

                return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
            }

            $property = $locked->property;
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($locked->property_id !== $actor->property_id || ! in_array($locked->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
                return PostingResult::refused(PostingResult::REFUSAL_UNIT_NOT_POSTABLE);
            }

            $item = null;
            $code = null;

            if ($data->chargeItemId !== null) {
                $item = ChargeItem::query()->where('property_id', $locked->property_id)->active()->with('transactionCode')->find($data->chargeItemId);
                $code = $item?->transactionCode;
            } elseif ($data->transactionCodeId !== null) {
                $code = TransactionCode::query()->where('property_id', $locked->property_id)->where('active', true)->find($data->transactionCodeId);
            }

            if ($code === null || $code->kind !== TransactionKind::Charge || ($data->chargeItemId !== null && $item === null)) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($data->quantity < 1 || $data->quantity > 999) {
                return PostingResult::refused(PostingResult::REFUSAL_QUANTITY_INVALID);
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $data->unitAmount) !== 1 || (float) $data->unitAmount <= 0) {
                return PostingResult::refused(PostingResult::REFUSAL_AMOUNT_INVALID);
            }

            $currency = $property->settings?->currency->getCurrencyCode()
                ?? throw new InvalidArgumentException('The property needs settings with a currency to post a charge.');
            $unitAmount = Money::of($data->unitAmount, $currency);

            if ($item !== null) {
                $default = $item->default_amount === null ? null : Money::of($item->default_amount, $currency);

                if (! $item->allow_override && ($default === null || ! $unitAmount->isEqualTo($default))) {
                    return PostingResult::refused(PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
                }
            }

            if ($data->serviceDate !== null && ! $this->serviceDateRange->allows($property, $locked->reservation, $businessDate, $data->serviceDate)) {
                return PostingResult::refused(PostingResult::REFUSAL_SERVICE_DATE_INVALID);
            }

            if ($data->folioId !== null) {
                $folio = Folio::query()->find($data->folioId);

                if ($folio === null || $folio->reservation_id !== $locked->reservation_id) {
                    return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
                }

                if ($folio->type === FolioType::Unit && $folio->reservation_unit_id !== $locked->id) {
                    return PostingResult::refused(PostingResult::REFUSAL_UNIT_NOT_ON_FOLIO);
                }
            } elseif ($data->ownFolio) {
                $folio = $this->folioRouter->unitFolio($locked);
            } else {
                $folio = $this->folioRouter->targetFor($locked, $code);
            }

            if ($folio !== null && $folio->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
            }

            $entered = $unitAmount->multipliedBy($data->quantity);
            $nationality = $this->unitNationality->for($locked);

            if ($data->taxIds === null) {
                $tax = $this->taxCalculator->calculate($property, $code, $entered, (string) $data->quantity, $nationality);
            } else {
                $chosen = $property->taxes()->whereKey($data->taxIds)->get();

                if ($chosen->count() !== count(array_unique($data->taxIds))) {
                    return PostingResult::refused(PostingResult::REFUSAL_TAX_NOT_AVAILABLE);
                }

                $tax = $this->taxCalculator->calculateWithTaxes($property, $chosen, $entered, (string) $data->quantity, $nationality);
            }

            if (! $tax->isCalculated()) {
                return PostingResult::refused((string) $tax->refusalReason);
            }

            $folio ??= ($this->createFolio)($locked->reservation, $locked);
            $addOn = $data->unitAddOnId === null ? null : $locked->addOns()->lockForUpdate()->with(['chargeItem', 'ageGroup'])->findOrFail($data->unitAddOnId);

            $lines = ($this->postFolioCharge)(
                $actor,
                $folio,
                $code,
                filled($data->description) ? trim((string) $data->description) : ($addOn instanceof UnitAddOn ? AddOnLabel::for($addOn) : ($item->name ?? $code->name)),
                $data->quantity,
                $unitAmount,
                $tax,
                $key,
                $businessDate->toDateString(),
                $addOn ?? $locked,
                serviceDate: $data->serviceDate,
                manualBatchId: $data->manualBatchId,
            );
            $charge = $lines[0];

            if ($addOn instanceof UnitAddOn) {
                $addOn->folio_line_id = $charge->id;
                $addOn->save();
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('folio.charge_posted')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $charge->id,
                    'transaction_code_id' => $code->id,
                    'quantity' => $data->quantity,
                    'unit_amount' => $charge->unit_amount,
                    'amount' => $charge->amount,
                    'tax_lines' => count($lines) - 1,
                    'unit_add_on_id' => $addOn?->id,
                    'posting_date' => $businessDate->toDateString(),
                    'service_date' => $charge->service_date->toDateString(),
                    'manual_batch_id' => $data->manualBatchId,
                ])
                ->log('Charge posted to folio');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
