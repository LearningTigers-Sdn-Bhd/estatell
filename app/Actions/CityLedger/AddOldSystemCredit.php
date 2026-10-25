<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Enums\PaymentMethodType;
use App\Enums\TransactionKind;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AddOldSystemCredit
{
    public function __construct(
        private MigrationWindow $migrationWindow,
        private FirstBusinessDate $firstBusinessDate,
    ) {}

    /**
     * Bring money a company paid in advance in the old PMS into the city ledger: an AR payment on the Old system
     * method with the old receipt number as its reference, the old date and no receipt number of its own. It stays
     * unallocated, so it is credit on the company. The company row is locked first and every check runs before the
     * first write, so a refusal writes and logs nothing. `$importing` (only the wastays balances import passes it) lets a payment keep its real date when the hotel replays its history. Permissions (`folio.manual`) are checked by the route. See
     * docs/15-city-ledger/05-old-system-balances.md#credit-on-account.
     */
    public function __invoke(User $actor, Company $company, string $oldReceiptNumber, CarbonImmutable $receivedOn, string $amount, string $reason, string $idempotencyKey, bool $importing = false): ArPaymentResult
    {
        return DB::transaction(function () use ($actor, $company, $oldReceiptNumber, $receivedOn, $amount, $reason, $idempotencyKey, $importing): ArPaymentResult {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            $existing = ArPayment::query()
                ->where('property_id', $locked->property_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing->company_id === $locked->id
                    ? ArPaymentResult::replayed($existing)
                    : ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
            }
            $property = $locked->property;

            if (! $this->migrationWindow->isOpen($property)) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_MIGRATION_WINDOW_CLOSED);
            }

            if ($locked->status !== CompanyStatus::Active) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_COMPANY_NOT_AVAILABLE);
            }

            $method = PaymentMethod::query()
                ->where('property_id', $locked->property_id)
                ->where('type', PaymentMethodType::OldSystem)
                ->whereHas('transactionCode', fn ($code) => $code->where('active', true)->where('kind', TransactionKind::Payment))
                ->first();

            if ($method === null) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
            }

            $reference = trim($oldReceiptNumber);
            $reason = trim($reason);
            $goLive = $this->firstBusinessDate->date($property);

            if ($goLive === null || (! $importing && $receivedOn->startOfDay()->greaterThanOrEqualTo($goLive))) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_DATE_NOT_BEFORE_GO_LIVE);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || BigDecimal::of($amount)->isNegativeOrZero()) {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            if ($reference === '') {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_REFERENCE_REQUIRED);
            }

            if ($reason === '') {
                return ArPaymentResult::refused(ArPaymentResult::REFUSAL_REASON_REQUIRED);
            }

            $payment = new ArPayment([
                'company_id' => $locked->id,
                'payment_method_id' => $method->id,
                'amount' => (string) BigDecimal::of($amount)->toScale(2),
                'received_on' => $receivedOn->startOfDay(),
                'reference' => $reference,
                'notes' => $reason,
                'receipt_number' => null,
                'idempotency_key' => $idempotencyKey,
                'received_by' => $actor->id,
            ]);
            $payment->property_id = $locked->property_id;
            $payment->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('city_ledger.old_credit_added')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_payment_id' => $payment->id,
                    'reference' => $reference,
                    'received_on' => $receivedOn->toDateString(),
                    'amount' => $payment->amount,
                    'reason' => $reason,
                ])
                ->log('Old system credit added');

            return ArPaymentResult::done($payment);
        });
    }
}
