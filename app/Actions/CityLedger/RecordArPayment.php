<?php

namespace App\Actions\CityLedger;

use App\Data\CityLedger\NewArPaymentData;
use App\Enums\DocumentType;
use App\Enums\PaymentMethodType;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\NextDocumentNumber;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class RecordArPayment
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private NextDocumentNumber $nextDocumentNumber,
        private AutoAllocateArPayment $autoAllocateArPayment,
    ) {}

    /**
     * Record money a company paid against its city ledger. The company row is locked first and every check runs
     * before the first write, so a refusal writes and logs nothing. The receipt number comes from the property's
     * `Receipt` sequence. A repeated `idempotencyKey` returns the existing payment of that company. With `$allocate`
     * the payment is matched to the company's oldest open invoices in the same transaction. Permissions are checked by
     * the route. See docs/15-city-ledger/03-ar-payments.md.
     */
    public function __invoke(User $actor, Company $company, NewArPaymentData $data, bool $allocate = false): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $company, $data, $allocate): ArPaymentResult {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            $existing = ArPayment::query()
                ->where('property_id', $locked->property_id)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing->company_id === $locked->id
                    ? ArPaymentResult::replayed($existing)
                    : ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $method = PaymentMethod::query()
                ->where('property_id', $locked->property_id)
                ->active()
                ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
                ->find($data->paymentMethodId);

            if ($method === null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $data->amount) || BigDecimal::of($data->amount)->isNegativeOrZero()) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            $reference = filled($data->reference) ? trim((string) $data->reference) : null;

            if ($method->requires_reference && $reference === null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_REFERENCE_REQUIRED);
            }

            $receivedOn = $data->receivedOn?->startOfDay() ?? $businessDate;

            if ($receivedOn->gt($businessDate)) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_DATE_IN_FUTURE);
            }

            $payment = new ArPayment([
                'company_id' => $locked->id,
                'payment_method_id' => $method->id,
                'amount' => (string) BigDecimal::of($data->amount)->toScale(2),
                'received_on' => $receivedOn,
                'reference' => $reference,
                'notes' => filled($data->notes) ? trim((string) $data->notes) : null,
                'receipt_number' => ($this->nextDocumentNumber)($locked->property, DocumentType::Receipt)->reference,
                'idempotency_key' => $data->idempotencyKey,
                'received_by' => $actor->id,
            ]);
            $payment->property_id = $locked->property_id;
            $payment->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('ar_payment.recorded')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_payment_id' => $payment->id,
                    'receipt_number' => $payment->receipt_number,
                    'payment_method_id' => $method->id,
                    'amount' => $payment->amount,
                    'received_on' => $receivedOn->toDateString(),
                ])
                ->log('AR payment recorded');

            if (! $allocate) {
                return ArPaymentResult::done($payment);
            }

            $allocated = ($this->autoAllocateArPayment)($actor, $payment);

            return ArPaymentResult::done($payment, $allocated->allocations);
        });
    }
}
