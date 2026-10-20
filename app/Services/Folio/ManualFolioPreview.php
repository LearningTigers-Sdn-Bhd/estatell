<?php

namespace App\Services\Folio;

use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\PostingResult;
use App\Actions\Payments\PaymentResult;
use App\Data\Folio\ManualPreviewData;
use App\Data\Folio\ManualRailFolioData;
use App\Data\Folio\ManualRowData;
use App\Data\Folio\ManualRowPreviewData;
use App\Data\Folio\ManualTaxData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\ManualRowType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Payments\AvailablePaymentMethod;
use App\Services\Payments\PaymentQuote;
use App\Services\Payments\PaymentRefusals;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reads the manual folio page and writes nothing: for every draft row its tax lines, signed total and first refusal,
 * then the rail figures of the folio being managed and the balance of every folio the rows touch, now and after. It
 * applies the checks of `PostCharge`, `RecordPayment` and `RefundPayment` before the first write, so a row it accepts
 * is a row `PostManualBatch` posts for the same total. A refused row adds nothing to the figures.
 * See docs/13-folio/08-manual-folio.md.
 */
class ManualFolioPreview
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ServiceDateRange $serviceDateRange,
        private TaxCalculator $taxCalculator,
        private PaymentQuote $paymentQuote,
        private AvailablePaymentMethod $availablePaymentMethod,
        private UnitNationality $unitNationality,
        private FolioPostability $folioPostability,
        private FolioRefusals $folioRefusals,
        private PaymentRefusals $paymentRefusals,
        private InHouseUnits $inHouseUnits,
        private IncomingScheduledCharges $incomingScheduledCharges,
        private MoveChargesPreview $labels,
        private FolioRouter $folioRouter,
    ) {}

    /**
     * @param  list<ManualRowData>  $rows
     */
    public function preview(User $actor, Property $property, Reservation $reservation, Folio $folio, array $rows): ManualPreviewData
    {
        if ($folio->reservation_id !== $reservation->id) {
            throw new InvalidArgumentException('The folio belongs to another reservation.');
        }

        $currency = $property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to preview manual entries.');
        $context = [
            'actor' => $actor,
            'property' => $property,
            'reservation' => $reservation,
            'currency' => $currency,
            'businessDate' => $this->currentBusinessDate->date($property),
            'folios' => $reservation->folios()->with(['payerGuest', 'payerCompany:id,name'])->get()->keyBy('id'),
            'units' => $reservation->units()->get()->keyBy('id'),
            'refunded' => [],
        ];
        $answers = [];

        foreach ($rows as $row) {
            $answers[] = $this->evaluate($row, $context);
        }

        $touched = collect([$folio->id, ...array_map(fn (array $answer): ?int => $answer['folio'], $answers)])->filter()->unique()->sort()->values();
        $now = $this->figures(array_values($touched->all()), $currency);
        $after = $now;

        foreach ($answers as $answer) {
            if ($answer['folio'] === null) {
                continue;
            }

            $after[$answer['folio']] = [
                'charges' => $after[$answer['folio']]['charges']->plus($answer['charges']),
                'payments' => $after[$answer['folio']]['payments']->plus($answer['payments']),
            ];
        }

        $unitLabels = $this->labels->unitLabels($reservation);
        $balance = fn (array $figures): Money => $figures['charges']->minus($figures['payments']);

        return new ManualPreviewData(
            rows: array_map(fn (array $answer): ManualRowPreviewData => $answer['preview'], $answers),
            chargesNow: $this->text($now[$folio->id]['charges']),
            chargesAfter: $this->text($after[$folio->id]['charges']),
            paymentsNow: $this->text($now[$folio->id]['payments']),
            paymentsAfter: $this->text($after[$folio->id]['payments']),
            balanceNow: $this->text($balance($now[$folio->id])),
            balanceAfter: $this->text($balance($after[$folio->id])),
            folios: array_values($touched->map(fn (int $id): ManualRailFolioData => new ManualRailFolioData(
                $id,
                $this->labels->folioLabel($context['folios'][$id], $unitLabels),
                $this->text($balance($now[$id])),
                $this->text($balance($after[$id])),
            ))->all()),
            closeBlocked: $this->closeBlocked($folio),
        );
    }

    /**
     * The message of the first reason the folio cannot close, or null. The balance is not one: the page ticks
     * Close after posting only when the balance after posting is 0.00.
     */
    private function closeBlocked(Folio $folio): ?string
    {
        if ($folio->status !== FolioStatus::Open) {
            return $this->folioRefusals->message(FolioStatusResult::REFUSAL_NOT_OPEN);
        }

        $rooms = $this->inHouseUnits->roomsFor($folio);

        if ($rooms !== []) {
            return $this->folioRefusals->message(FolioStatusResult::REFUSAL_UNITS_IN_HOUSE, $rooms);
        }

        $incoming = $this->incomingScheduledCharges->for($folio);

        return $incoming === [] ? null : $this->folioRefusals->message(FolioStatusResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $incoming);
    }

    /**
     * The posted charges and payments of each folio. Charges are every line that is not a payment line (taxes and
     * discounts included), payments the negated sum of the payment lines, so charges minus payments is the balance.
     *
     * @param  list<int>  $folioIds
     * @return array<int, array{charges: Money, payments: Money}>
     */
    private function figures(array $folioIds, string $currency): array
    {
        $figures = array_fill_keys($folioIds, ['charges' => Money::zero($currency), 'payments' => Money::zero($currency)]);

        FolioLine::query()
            ->whereIn('folio_id', $folioIds)
            ->groupBy('folio_id')
            ->selectRaw("folio_id, COALESCE(SUM(amount) FILTER (WHERE kind <> 'payment'), 0)::numeric(14, 2) AS charges, COALESCE(-SUM(amount) FILTER (WHERE kind = 'payment'), 0)::numeric(14, 2) AS payments")
            ->get()
            ->each(function (FolioLine $line) use (&$figures, $currency): void {
                $figures[$line->folio_id] = [
                    'charges' => Money::of((string) $line->getAttribute('charges'), $currency),
                    'payments' => Money::of((string) $line->getAttribute('payments'), $currency),
                ];
            });

        return $figures;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview: ManualRowPreviewData, folio: ?int, charges: Money, payments: Money}
     */
    private function evaluate(ManualRowData $row, array &$context): array
    {
        $zero = Money::zero($context['currency']);

        if ($context['businessDate'] === null) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_NO_BUSINESS_DATE));
        }

        if (! $this->dateAllowed($row, $context)) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_SERVICE_DATE_INVALID));
        }

        return match ($row->type) {
            ManualRowType::Charge => $this->charge($row, $context),
            ManualRowType::Payment => $this->payment($row, $context),
            ManualRowType::Refund => $this->refund($row, $context),
        };
    }

    /**
     * Whether the row's service date is allowed. An Old system payment skips the range: any real day up to the business date.
     *
     * @param  array<string, mixed>  $context
     */
    private function dateAllowed(ManualRowData $row, array $context): bool
    {
        $isOldSystem = $row->type === ManualRowType::Payment
            && $row->paymentMethodId !== null
            && PaymentMethod::query()->where('property_id', $context['property']->id)->whereKey($row->paymentMethodId)->where('type', PaymentMethodType::OldSystem)->exists();

        return $isOldSystem
            ? $this->serviceDateRange->allowsOldSystemPayment($context['businessDate'], $row->serviceDate)
            : $this->serviceDateRange->allows($context['property'], $context['reservation'], $context['businessDate'], $row->serviceDate);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview: ManualRowPreviewData, folio: ?int, charges: Money, payments: Money}
     */
    private function charge(ManualRowData $row, array $context): array
    {
        /** @var Property $property */
        $property = $context['property'];
        $zero = Money::zero($context['currency']);
        $folio = $context['folios']->get($row->folioId);

        if ($folio === null) {
            throw new InvalidArgumentException('Every folio must belong to the reservation.');
        }

        $unit = $this->unit($folio, $row, $context['units']);

        if ($unit === null) {
            return $this->refused($row, $zero, ['unit_id' => __('Pick the room this charge is for.')]);
        }

        if (! in_array($unit->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_UNIT_NOT_POSTABLE));
        }

        $item = $row->chargeItemId === null ? null : ChargeItem::query()->where('property_id', $property->id)->active()->with('transactionCode')->find($row->chargeItemId);
        $code = $item?->transactionCode;

        if ($code === null || $code->kind !== TransactionKind::Charge) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_CHARGE_NOT_FOUND));
        }

        if ($row->quantity < 1 || $row->quantity > 999) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_QUANTITY_INVALID));
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $row->amount) !== 1 || (float) $row->amount <= 0) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_AMOUNT_INVALID));
        }

        $unitAmount = Money::of($row->amount, $context['currency']);
        $default = $item->default_amount === null ? null : Money::of($item->default_amount, $context['currency']);

        if (! $item->allow_override && ($default === null || ! $unitAmount->isEqualTo($default))) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED));
        }

        $taxes = $this->taxes($property, $row, $code->id);

        if ($taxes === null) {
            return $this->refused($row, $zero, $this->folioRefusals->errors(PostingResult::REFUSAL_TAX_NOT_AVAILABLE));
        }

        if ($folio->status !== FolioStatus::Open) {
            return $this->refused($row, $zero, $this->folioRefusals->errors((string) $this->folioPostability->refusalFor($folio)));
        }

        $nationality = $this->unitNationality->for($unit);
        $tax = $this->taxCalculator->calculateWithTaxes($property, $taxes, $unitAmount->multipliedBy($row->quantity), (string) $row->quantity, $nationality);

        if (! $tax->isCalculated()) {
            return $this->refused($row, $zero, $this->folioRefusals->errors((string) $tax->refusalReason));
        }

        $exempt = $taxes
            ->filter(fn (Tax $tax): bool => $tax->enabled && $tax->foreign_guests_only && $nationality !== null && mb_strtoupper($nationality) === mb_strtoupper((string) $property->country_code))
            ->map(fn (Tax $tax): ManualTaxData => new ManualTaxData($tax->name, (string) $zero->getAmount(), true));
        $lines = collect($tax->taxes)->map(fn ($component): ManualTaxData => new ManualTaxData($component->name, $this->text($component->amount), false));

        return [
            'preview' => new ManualRowPreviewData($row->key, $this->text($tax->grossAmount), [...$lines->all(), ...$exempt->values()->all()], null, null),
            'folio' => $folio->id,
            'charges' => $tax->grossAmount,
            'payments' => $zero,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview: ManualRowPreviewData, folio: ?int, charges: Money, payments: Money}
     */
    private function payment(ManualRowData $row, array $context): array
    {
        $zero = Money::zero($context['currency']);
        $folio = $context['folios']->get($row->folioId);

        if ($folio === null) {
            throw new InvalidArgumentException('Every folio must belong to the reservation.');
        }

        if ($folio->status !== FolioStatus::Open) {
            return $this->refused($row, $zero, $this->folioRefusals->errors((string) $this->folioPostability->refusalFor($folio)));
        }

        $purposeRefusal = $this->purposeRefusal($folio, $row);

        if ($purposeRefusal !== null) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors($purposeRefusal));
        }

        $method = $row->paymentMethodId === null ? null : $this->availablePaymentMethod->find($context['reservation'], $row->paymentMethodId, $context['actor']);

        if ($method === null) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE));
        }

        $quote = $this->paymentQuote->quote($context['reservation'], $method, $row->amount);

        if ($quote->refusal === PaymentResult::REFUSAL_AMOUNT_INVALID) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_AMOUNT_INVALID));
        }

        if (($method->requires_reference || $method->type === PaymentMethodType::OldSystem) && ! filled($row->reference)) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_REFERENCE_REQUIRED));
        }

        if ($quote->isRefused()) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors((string) $quote->refusal));
        }

        return [
            'preview' => new ManualRowPreviewData($row->key, $this->text($quote->total->negated()), [], null, null),
            'folio' => $folio->id,
            'charges' => $quote->total->minus($quote->amount),
            'payments' => $quote->total,
        ];
    }

    /**
     * The refusal `RecordPayment` would give for the row's purpose on this folio, else null.
     */
    private function purposeRefusal(Folio $folio, ManualRowData $row): ?string
    {
        $purpose = $row->purpose ?? PaymentPurpose::Settlement;

        return match (true) {
            $purpose === PaymentPurpose::SecurityDeposit && $folio->type !== FolioType::Unit => PaymentResult::REFUSAL_DEPOSIT_ON_UNIT_ONLY,
            $folio->type === FolioType::ThirdParty && $purpose !== PaymentPurpose::Settlement => PaymentResult::REFUSAL_PURPOSE_NOT_OFFERED,
            $purpose === PaymentPurpose::Advance && $folio->type === FolioType::Unit && $this->roomChargesGoToMaster($folio) => PaymentResult::REFUSAL_ADVANCE_ON_MASTER,
            default => null,
        };
    }

    private function roomChargesGoToMaster(Folio $folio): bool
    {
        $unit = ReservationUnit::query()->find($folio->reservation_unit_id);

        return $unit !== null && $this->folioRouter->roomChargesGoToMaster($unit);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview: ManualRowPreviewData, folio: ?int, charges: Money, payments: Money}
     */
    private function refund(ManualRowData $row, array &$context): array
    {
        $zero = Money::zero($context['currency']);
        $payment = $row->paymentId === null ? null : Payment::query()->whereIn('folio_id', $context['folios']->keys())->find($row->paymentId);

        if ($payment === null) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_PAYMENT_NOT_FOUND));
        }

        $folio = $context['folios'][$payment->folio_id];

        if ($folio->status !== FolioStatus::Open) {
            return $this->refused($row, $zero, $this->folioRefusals->errors((string) $this->folioPostability->refusalFor($folio)));
        }

        if ($payment->refund_of_id !== null || $payment->status !== PaymentStatus::Completed) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_NOT_REFUNDABLE));
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $row->amount) !== 1 || (float) $row->amount <= 0) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_AMOUNT_INVALID));
        }

        $amount = Money::of($row->amount, $context['currency']);
        $refunded = Money::of((string) ($payment->refunds()->sum('amount') ?: '0'), $context['currency'])->plus($context['refunded'][$payment->id] ?? $zero);

        if ($amount->isGreaterThan(Money::of($payment->amount, $context['currency'])->minus($refunded))) {
            return $this->refused($row, $zero, $this->paymentRefusals->errors(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT));
        }

        $context['refunded'][$payment->id] = ($context['refunded'][$payment->id] ?? $zero)->plus($amount);

        return [
            'preview' => new ManualRowPreviewData($row->key, $this->text($amount), [], null, null),
            'folio' => $folio->id,
            'charges' => $zero,
            'payments' => $amount->negated(),
        ];
    }

    /**
     * The unit a charge is for: the folio's own on a Unit folio, else the one the row names.
     *
     * @param  Collection<int, ReservationUnit>  $units
     */
    private function unit(Folio $folio, ManualRowData $row, Collection $units): ?ReservationUnit
    {
        $unitId = $folio->type === FolioType::Unit ? $folio->reservation_unit_id : $row->unitId;

        return $unitId === null ? null : $units->get($unitId);
    }

    /**
     * The taxes to calculate: the row's own choice, else the item code's taxes. Null when a chosen tax is not the
     * property's.
     *
     * @return Collection<int, Tax>|null
     */
    private function taxes(Property $property, ManualRowData $row, int $codeId): ?Collection
    {
        if ($row->taxIds === null) {
            return $property->taxes()->whereHas('applicableTransactionCodes', fn ($query) => $query->whereKey($codeId))->get();
        }

        $chosen = $property->taxes()->whereKey($row->taxIds)->get();

        return $chosen->count() === count(array_unique($row->taxIds)) ? $chosen : null;
    }

    /**
     * @param  array<string, string>  $errors  One field and its message.
     * @return array{preview: ManualRowPreviewData, folio: ?int, charges: Money, payments: Money}
     */
    private function refused(ManualRowData $row, Money $zero, array $errors): array
    {
        return [
            'preview' => new ManualRowPreviewData($row->key, null, [], array_key_first($errors), reset($errors) ?: null),
            'folio' => null,
            'charges' => $zero,
            'payments' => $zero,
        ];
    }

    private function text(Money $money): string
    {
        return (string) $money->getAmount();
    }
}
