<?php

namespace App\Actions\Payments;

use App\Actions\Folio\CloseSettledUnitFolio;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\ServiceDateRange;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundPayment
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
        private ServiceDateRange $serviceDateRange,
    ) {}

    /**
     * Give money back from a payment. A refund is a payment row with `refund_of_id`, the same purpose and method, no
     * receipt number, and a positive line with the system Refund code on the payment's folio. A refund of a payment copied from the old system is `migrated` too. The surcharge is not
     * refunded: staff reverse that charge by hand. The folio row is locked first, so two refunds of one payment never
     * exceed it, and every check runs before the first write. The key `refund:{uuid}` makes a repeat return the
     * existing refund. The last step closes the folio when it is an Open Unit folio of a CheckedOut unit at 0
     * (`CloseSettledUnitFolio`). `$methodId` gives the money back by another method: it needs `payment.refund-other-method`
     * and a `$reason`, and only a cash payment goes back in cash. `$void` refunds a whole payment taken on the current
     * business date that has no refund yet. See docs/14-payments/01-payments-and-refunds.md.
     */
    public function __invoke(User $actor, Payment $payment, string $amount, ?string $reference, string $idempotencyKey, ?string $serviceDate = null, ?int $manualBatchId = null, ?int $methodId = null, ?string $reason = null, bool $void = false): PaymentResult
    {
        return DB::transaction(function () use ($actor, $payment, $amount, $reference, $idempotencyKey, $serviceDate, $manualBatchId, $methodId, $reason, $void): PaymentResult {
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

            if ($void) {
                if ($refunded->isPositive()) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_VOID_HAS_REFUND);
                }

                $paidOn = FolioLine::query()->whereKey($current->folio_line_id)->value('posting_date');

                if ($paidOn === null || ! $businessDate->isSameDay(CarbonImmutable::parse((string) $paidOn))) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_VOID_NOT_SAME_DAY);
                }
            }

            if ($refundAmount->isGreaterThan($left)) {
                return PaymentResult::refused(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT);
            }

            $methodId = $methodId === $current->payment_method_id ? null : $methodId;
            $reason = filled($reason) ? trim((string) $reason) : null;

            if ($methodId !== null) {
                $target = PaymentMethod::query()
                    ->where('property_id', $folio->property_id)
                    ->where('active', true)
                    ->whereIn('type', PaymentMethodType::addableCases())
                    ->whereHas('transactionCode', fn ($query) => $query->where('active', true))
                    ->find($methodId);

                if ($target === null) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
                }

                if (! $actor->can(PermissionEnum::PaymentRefundOtherMethod->value)) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_OTHER_METHOD_NOT_ALLOWED);
                }

                if ($reason === null) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_REASON_REQUIRED);
                }

                if ($target->type === PaymentMethodType::Cash && $current->paymentMethod->type !== PaymentMethodType::Cash) {
                    return PaymentResult::refused(PaymentResult::REFUSAL_REFUND_METHOD_MISMATCH);
                }
            }

            $code = TransactionCode::query()
                ->where('property_id', $folio->property_id)
                ->where('system_key', SystemTransactionCode::Refund->value)
                ->firstOrFail();
            if ($serviceDate !== null && ! $this->serviceDateRange->allows($property, $folio->reservation, $businessDate, $serviceDate)) {
                return PaymentResult::refused(PaymentResult::REFUSAL_SERVICE_DATE_INVALID);
            }

            $postingDate = $businessDate->toDateString();
            $serviceDate ??= $postingDate;
            $value = (string) $refundAmount->getAmount();

            $line = new FolioLine([
                'transaction_code_id' => $code->id,
                'kind' => TransactionKind::Payment,
                'description' => $void ? __('Void payment') : $code->name,
                'quantity' => 1,
                'unit_amount' => $value,
                'amount' => $value,
            ]);
            $line->folio_id = $folio->id;
            $line->posting_date = $postingDate;
            $line->service_date = $serviceDate;
            $line->manual_batch_id = $manualBatchId;
            $line->migrated = (bool) FolioLine::query()->whereKey($current->folio_line_id)->value('migrated');
            $line->idempotency_key = $key;
            $line->user_id = $actor->id;
            $line->save();

            $refund = new Payment([
                'folio_id' => $folio->id,
                'payment_method_id' => $methodId ?? $current->payment_method_id,
                'amount' => $value,
                'purpose' => $current->purpose,
                'status' => PaymentStatus::Completed,
                'reference' => filled($reference) ? trim((string) $reference) : null,
                'refund_of_id' => $current->id,
                'refund_reason' => $methodId === null ? null : $reason,
                'voided' => $void,
                'folio_line_id' => $line->id,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $refund->property_id = $folio->property_id;
            $refund->save();

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event($void ? 'payment.voided' : 'payment.refunded')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'payment_id' => $current->id,
                    'refund_id' => $refund->id,
                    'amount' => $value,
                    'payment_method_id' => $refund->payment_method_id,
                    'reason' => $refund->refund_reason,
                    'posting_date' => $postingDate,
                    'service_date' => $serviceDate,
                    'manual_batch_id' => $manualBatchId,
                ])
                ->log($void ? 'Payment voided' : 'Payment refunded');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PaymentResult::recorded($refund->refresh(), [$line->refresh()]);
        });
    }
}
