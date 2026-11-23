<?php

namespace App\Actions\Events;

use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\PostingResult;
use App\Data\Folio\NewChargeData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\FolioPostability;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostEventCharge
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioPostability $folioPostability,
        private TaxCalculator $taxCalculator,
        private PostFolioCharge $postFolioCharge,
    ) {}

    /**
     * Post one charge on an event folio, then one Tax child line for each tax component. An event folio has no
     * units and no routing, so the charge lands on this folio and the line has no source. The folio row is locked
     * first, and every check runs before the first write, so a refusal writes and logs nothing. The checks match
     * `PostCharge`: the charge item or code (`charge_not_found`), quantity, amount and price override. A folio that
     * is not an Open Event folio of the actor's property is refused (`charge_not_found`, or the `FolioPostability`
     * refusal when it is not Open). Tax uses the payer guest's nationality. A key that already exists on this folio
     * returns the existing lines. Logs `folio.charge_posted` on the event.
     * See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(User $actor, Folio $folio, NewChargeData $data): PostingResult
    {
        return DB::transaction(function () use ($actor, $folio, $data): PostingResult {
            $locked = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $key = $data->idempotencyKey ?? 'manual:'.Str::uuid();
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing->folio_id === $locked->id
                    ? PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()])
                    : PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($locked->type !== FolioType::Event || ($locked->property_id !== $actor->property_id && ! $actor->is_admin)) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($locked->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($locked));
            }

            $property = $locked->property;
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
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

            $event = $locked->event()->with('payerGuest')->firstOrFail();
            $tax = $this->taxCalculator->calculate($property, $code, $unitAmount->multipliedBy($data->quantity), (string) $data->quantity, $event->payerGuest?->nationality_code);

            if (! $tax->isCalculated()) {
                return PostingResult::refused((string) $tax->refusalReason);
            }

            $lines = ($this->postFolioCharge)(
                $actor,
                $locked,
                $code,
                filled($data->description) ? trim((string) $data->description) : ($item->name ?? $code->name),
                $data->quantity,
                $unitAmount,
                $tax,
                $key,
                $businessDate->toDateString(),
            );
            $charge = $lines[0];

            activity()
                ->performedOn($event)
                ->causedBy($actor)
                ->event('folio.charge_posted')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'folio_id' => $locked->id,
                    'folio_line_id' => $charge->id,
                    'transaction_code_id' => $code->id,
                    'quantity' => $data->quantity,
                    'unit_amount' => $charge->unit_amount,
                    'amount' => $charge->amount,
                    'tax_lines' => count($lines) - 1,
                    'posting_date' => $businessDate->toDateString(),
                ])
                ->log('Charge posted to folio');

            return PostingResult::posted($lines);
        });
    }
}
