<?php

namespace App\Actions\Folio;

use App\Data\Folio\NewChargeData;
use App\Enums\FolioStatus;
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
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostCharge
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioRouter $folioRouter,
        private TaxCalculator $taxCalculator,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Post one charge on a unit's folio, then one Tax child line for each tax component. The unit row is locked first,
     * so two postings of one unit never race. Every check runs before the first write, so a refusal writes and logs
     * nothing. The folio comes from `FolioRouter` (a routing rule or the unit's own folio), and a closed one refuses the posting with
     * no fallback. Tax lines go on the folio of their charge. A key that already exists returns the existing lines and writes nothing, but only when the line's folio belongs to the unit's reservation (otherwise `charge_not_found`). The charge line holds the net
     * amount (the entered amount without inclusive tax), so the folio total is the gross amount.
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

            $currency = $property->settings?->currency->value
                ?? throw new InvalidArgumentException('The property needs settings with a currency to post a charge.');
            $unitAmount = Money::of($data->unitAmount, $currency);

            if ($item !== null) {
                $default = $item->default_amount === null ? null : Money::of($item->default_amount, $currency);

                if (! $item->allow_override && ($default === null || ! $unitAmount->isEqualTo($default))) {
                    return PostingResult::refused(PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
                }
            }

            $folio = $this->folioRouter->targetFor($locked, $code);

            if ($folio !== null && $folio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $entered = $unitAmount->multipliedBy($data->quantity);
            $tax = $this->taxCalculator->calculate($property, $code, $entered, (string) $data->quantity, $locked->reservation->booker->nationality_code);

            if (! $tax->isCalculated()) {
                return PostingResult::refused((string) $tax->refusalReason);
            }

            $folio ??= ($this->createFolio)($locked->reservation, $locked);
            $addOn = $data->unitAddOnId === null ? null : $locked->addOns()->lockForUpdate()->findOrFail($data->unitAddOnId);

            $charge = $this->line($folio, $actor, $businessDate->toDateString(), $key, [
                'transaction_code_id' => $code->id,
                'kind' => TransactionKind::Charge,
                'description' => $item?->name ?? (filled($data->description) ? trim((string) $data->description) : $code->name),
                'quantity' => $data->quantity,
                'unit_amount' => (string) $unitAmount->getAmount(),
                'amount' => (string) $tax->netAmount->getAmount(),
                'source_type' => $addOn?->getMorphClass(),
                'source_id' => $addOn?->id,
            ]);
            $lines = [$charge];

            foreach ($tax->taxes as $component) {
                $lines[] = $this->line($folio, $actor, $businessDate->toDateString(), $key.':tax:'.$component->taxId, [
                    'transaction_code_id' => $component->transactionCodeId,
                    'kind' => TransactionKind::Tax,
                    'description' => $component->name,
                    'quantity' => 1,
                    'unit_amount' => (string) $component->amount->getAmount(),
                    'amount' => (string) $component->amount->getAmount(),
                    'parent_line_id' => $charge->id,
                ]);
            }

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
                ])
                ->log('Charge posted to folio');

            return PostingResult::posted($lines);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(Folio $folio, User $actor, string $postingDate, string $key, array $attributes): FolioLine
    {
        $line = new FolioLine($attributes);
        $line->folio_id = $folio->id;
        $line->posting_date = $postingDate;
        $line->idempotency_key = $key;
        $line->user_id = $actor->id;
        $line->save();

        return $line->refresh();
    }
}
