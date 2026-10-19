<?php

namespace App\Actions\Payments;

use App\Actions\Folio\CloseSettledUnitFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostFolioCharge;
use App\Data\Payments\NewPaymentData;
use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SurchargeType;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Payments\PaymentQuote;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordPayment
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PaymentQuote $paymentQuote,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private NextDocumentNumber $nextDocumentNumber,
        private FolioRouter $folioRouter,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Record a payment on a folio, or on a unit. Give exactly one. A unit with no folio yet gets its Unit folio here
     * (a deposit before check-in), and a unit that has one posts to it. The unit row (when given) and the folio row are
     * locked first, and every check runs before the first write, so a refusal writes and logs nothing. A SecurityDeposit
     * on a Master or ThirdParty folio is refused (`deposit_on_unit_only`), and so is an Advance on a unit whose Room
     * charges go to the Master folio (`advance_on_master`). The last step closes the folio when it is an Open Unit folio of a
     * CheckedOut unit at 0 (`CloseSettledUnitFolio`). A surcharge
     * posts as a charge on the same folio, and the payment line covers the amount, the fee and its tax, so the balance
     * falls by the amount entered. The key `payment:{uuid}` makes a repeat return the existing payment. The route
     * checks the permission and that the folio or unit belongs to the reservation's property.
     * See docs/14-payments/01-payments-and-refunds.md.
     */
    public function __invoke(User $actor, Reservation $reservation, ?Folio $folio, ?ReservationUnit $unit, NewPaymentData $data): PaymentResult
    {
        if (($folio === null) === ($unit === null)) {
            throw new InvalidArgumentException('Record a payment on a folio or on a unit, not both and not neither.');
        }

        return DB::transaction(function () use ($actor, $reservation, $folio, $unit, $data): PaymentResult {
            [$lockedUnit, $target] = $unit === null ? [null, $this->lockFolio($reservation, $folio)] : $this->lockUnit($reservation, $unit);
            $key = 'payment:'.$data->idempotencyKey;
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                $payment = Payment::query()->with('folio')->where('folio_line_id', $existing->id)->first();

                return $payment === null || $payment->folio->reservation_id !== $reservation->id
                    ? PaymentResult::refused(PaymentResult::REFUSAL_PAYMENT_NOT_FOUND)
                    : PaymentResult::replayed($payment);
            }

            $property = $reservation->property;
            $businessDate = $this->currentBusinessDate->date($property);

            if ($businessDate === null) {
                return PaymentResult::refused(PaymentResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($target !== null && $target->status !== FolioStatus::Open) {
                return PaymentResult::refused(PaymentResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            if ($data->purpose === PaymentPurpose::SecurityDeposit && $target !== null && $target->type !== FolioType::Unit) {
                return PaymentResult::refused(PaymentResult::REFUSAL_DEPOSIT_ON_UNIT_ONLY);
            }

            if ($data->purpose === PaymentPurpose::Advance && $this->roomsGoToMaster($reservation, $lockedUnit, $target)) {
                return PaymentResult::refused(PaymentResult::REFUSAL_ADVANCE_ON_MASTER);
            }

            $method = $this->availableMethod($reservation, $data->paymentMethodId);

            if ($method === null) {
                return PaymentResult::refused(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
            }

            $quote = $this->paymentQuote->quote($reservation, $method, $data->amount);

            if ($quote->refusal === PaymentResult::REFUSAL_AMOUNT_INVALID) {
                return PaymentResult::refused(PaymentResult::REFUSAL_AMOUNT_INVALID);
            }

            $reference = filled($data->reference) ? trim((string) $data->reference) : null;

            if ($method->requires_reference && $reference === null) {
                return PaymentResult::refused(PaymentResult::REFUSAL_REFERENCE_REQUIRED);
            }

            if ($quote->isRefused()) {
                return PaymentResult::refused((string) $quote->refusal);
            }

            $postingDate = $businessDate->toDateString();
            $target ??= ($this->createFolio)($reservation, $lockedUnit);
            $receiptNumber = ($this->nextDocumentNumber)($property, DocumentType::Receipt)->reference;
            $surchargeLines = [];

            if ($quote->tax !== null && $quote->surcharge !== null) {
                $item = $method->surchargeChargeItem;
                $surchargeLines = ($this->postFolioCharge)($actor, $target, $item->transactionCode, $item->name, 1, $quote->surcharge, $quote->tax, $key.':surcharge', $postingDate);
            }

            $total = (string) $quote->total?->getAmount();
            $line = new FolioLine([
                'transaction_code_id' => $method->transaction_code_id,
                'kind' => TransactionKind::Payment,
                'description' => $method->name,
                'quantity' => 1,
                'unit_amount' => '-'.$total,
                'amount' => '-'.$total,
            ]);
            $line->folio_id = $target->id;
            $line->posting_date = $postingDate;
            $line->service_date = $postingDate;
            $line->idempotency_key = $key;
            $line->user_id = $actor->id;
            $line->save();

            $payment = new Payment([
                'folio_id' => $target->id,
                'payment_method_id' => $method->id,
                'amount' => $total,
                'purpose' => $data->purpose,
                'status' => PaymentStatus::Completed,
                'reference' => $reference,
                'receipt_number' => $receiptNumber,
                'folio_line_id' => $line->id,
                'surcharge_line_id' => $surchargeLines[0]->id ?? null,
                'received_by' => $actor->id,
                'received_at' => now(),
            ]);
            $payment->property_id = $reservation->property_id;
            $payment->save();

            activity()
                ->performedOn($reservation)
                ->causedBy($actor)
                ->event('payment.recorded')
                ->withProperties([
                    'property_id' => $reservation->property_id,
                    'folio_id' => $target->id,
                    'payment_id' => $payment->id,
                    'payment_method_id' => $method->id,
                    'purpose' => $data->purpose->value,
                    'amount' => (string) $quote->amount?->getAmount(),
                    'surcharge' => (string) $quote->surcharge?->getAmount(),
                    'total' => $total,
                    'receipt_number' => $receiptNumber,
                    'posting_date' => $postingDate,
                ])
                ->log('Payment recorded');

            ($this->closeSettledUnitFolio)($actor, $target);

            return PaymentResult::recorded($payment->refresh(), [...$surchargeLines, $line->refresh()]);
        });
    }

    /**
     * Whether an Advance would land on a Unit folio (or a unit entry) whose Room charges go to the Master folio.
     */
    private function roomsGoToMaster(Reservation $reservation, ?ReservationUnit $lockedUnit, ?Folio $target): bool
    {
        if ($target !== null && $target->type !== FolioType::Unit) {
            return false;
        }

        $unit = $lockedUnit ?? ReservationUnit::query()->where('reservation_id', $reservation->id)->find($target?->reservation_unit_id);

        return $unit !== null && $this->folioRouter->roomChargesGoToMaster($unit);
    }

    private function lockFolio(Reservation $reservation, ?Folio $folio): Folio
    {
        $locked = Folio::query()->lockForUpdate()->findOrFail($folio?->id);

        if ($locked->reservation_id !== $reservation->id) {
            throw new InvalidArgumentException('The folio belongs to another reservation.');
        }

        return $locked;
    }

    /**
     * Lock the unit row, then its own folio when it has one.
     *
     * @return array{0: ReservationUnit, 1: Folio|null}
     */
    private function lockUnit(Reservation $reservation, ReservationUnit $unit): array
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

        if ($locked->reservation_id !== $reservation->id) {
            throw new InvalidArgumentException('The unit belongs to another reservation.');
        }

        $folio = Folio::query()
            ->where('property_id', $locked->property_id)
            ->where('reservation_unit_id', $locked->id)
            ->where('type', FolioType::Unit)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        return [$locked, $folio];
    }

    /**
     * An active method of the reservation's property that staff can take money with. City ledger and OTA collected
     * are not offered yet, and a surcharge needs an active charge item on an active charge code.
     */
    private function availableMethod(Reservation $reservation, int $methodId): ?PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('property_id', $reservation->property_id)
            ->active()
            ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
            ->with(['transactionCode', 'surchargeChargeItem.transactionCode'])
            ->find($methodId);

        if ($method === null || ! $method->transactionCode->active || $method->transactionCode->kind !== TransactionKind::Payment) {
            return null;
        }

        if ($method->surcharge_type === SurchargeType::None) {
            return $method;
        }

        $item = $method->surchargeChargeItem;

        return $item !== null && $item->active && $item->transactionCode->active && $item->transactionCode->kind === TransactionKind::Charge ? $method : null;
    }
}
