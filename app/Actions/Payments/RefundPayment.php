<?php

namespace App\Actions\Payments;

use App\Actions\Folio\CloseSettledUnitFolio;
use App\Enums\FolioStatus;
use App\Enums\PaymentStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundPayment
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Give money back from a payment. A refund is a payment row with `refund_of_id`, the same purpose and method, no
     * receipt number, and a positive line with the system Refund code on the payment's folio. The surcharge is not
     * refunded: staff reverse that charge by hand. The folio row is locked first, so two refunds of one payment never
     * exceed it, and every check runs before the first write. The key `refund:{uuid}` makes a repeat return the
     * existing refund. The last step closes the folio when it is an Open Unit folio of a CheckedOut unit at 0
     * (`CloseSettledUnitFolio`). See docs/14-payments/01-payments-and-refunds.md.
     */
    public function __invoke(User $actor, Payment $payment, string $amount, ?string $reference, string $idempotencyKey): PaymentResult
    {
        return DB::transaction(function () use ($actor, $payment, $amount, $reference, $idempotencyKey): PaymentResult {
            $current = Payment::query()->findOrFail($payment->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);
            $key = 'refund:'.$idempotencyKey;
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                $refund = Payment::query()->where('folio_line_id', $existing->id)->first();

                return $refund === null || $refund->refund_of_id !== $current->id
                    ? PaymentResult::refused(PaymentResult::REFUSAL_PAYMENT_NOT_FOUND)
                    : PaymentResult::replayed($refund);
            }

            $property = $folio->property;
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PaymentResult::refused(PaymentResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($current->refund_of_id !== null || $current->status !== PaymentStatus::Completed) {
                return PaymentResult::refused(PaymentResult::REFUSAL_NOT_REFUNDABLE);
            }

            if ($folio->status !== FolioStatus::Open) {
                return PaymentResult::refused(PaymentResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || (float) $amount <= 0) {
                return PaymentResult::refused(PaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            $currency = $property->settings?->currency->getCurrencyCode()
                ?? throw new InvalidArgumentException('The property needs settings with a currency to refund a payment.');
            $refunded = Money::of((string) ($current->refunds()->sum('amount') ?: '0'), $currency);
            $left = Money::of($current->amount, $currency)->minus($refunded);
            $refundAmount = Money::of($amount, $currency);

            if ($refundAmount->isGreaterThan($left)) {
                return PaymentResult::refused(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT);
            }

            $code = TransactionCode::query()
                ->where('property_id', $folio->property_id)
                ->where('system_key', SystemTransactionCode::Refund->value)
                ->firstOrFail();
            $postingDate = $businessDate->toDateString();
            $value = (string) $refundAmount->getAmount();

            $line = new FolioLine([
                'transaction_code_id' => $code->id,
                'kind' => TransactionKind::Payment,
                'description' => $code->name,
                'quantity' => 1,
                'unit_amount' => $value,
                'amount' => $value,
            ]);
            $line->folio_id = $folio->id;
            $line->posting_date = $postingDate;
            $line->service_date = $postingDate;
            $line->idempotency_key = $key;
            $line->user_id = $actor->id;
            $line->save();

            $refund = new Payment([
                'folio_id' => $folio->id,
                'payment_method_id' => $current->payment_method_id,
                'amount' => $value,
                'purpose' => $current->purpose,
                'status' => PaymentStatus::Completed,
                'reference' => filled($reference) ? trim((string) $reference) : null,
                'refund_of_id' => $current->id,
                'folio_line_id' => $line->id,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $refund->property_id = $folio->property_id;
            $refund->save();

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('payment.refunded')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'payment_id' => $current->id,
                    'refund_id' => $refund->id,
                    'amount' => $value,
                    'posting_date' => $postingDate,
                ])
                ->log('Payment refunded');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PaymentResult::recorded($refund->refresh(), [$line->refresh()]);
        });
    }
}
