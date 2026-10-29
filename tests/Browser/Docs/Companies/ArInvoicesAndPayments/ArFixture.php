<?php

namespace Tests\Browser\Docs\Companies\ArInvoicesAndPayments;

use App\Actions\CityLedger\AllocateArPayment;
use App\Actions\CityLedger\IssueCreditNote;
use App\Actions\CityLedger\RecordArPayment;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Folio\CreateFolio;
use App\Actions\FrontDesk\EarlyCheckOut;
use App\Actions\Payments\RecordPayment;
use App\Data\CityLedger\NewArPaymentData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the city ledger history Kinabalu does not have (no invoices, no payments) for the AR docs pages. Every step
 * calls the real Action as the signed-in Owner and fails the test when the Action refuses.
 */
final class ArFixture
{
    public static function company(Property $property): Company
    {
        return Company::query()->where('property_id', $property->id)->firstOrFail();
    }

    public static function method(Property $property, PaymentMethodType $type): PaymentMethod
    {
        return PaymentMethod::query()->where('property_id', $property->id)->where('type', $type)->where('active', true)->firstOrFail();
    }

    public static function masterFolio(Reservation $reservation): Folio
    {
        return Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Master)->firstOrFail();
    }

    /**
     * Check out every room of the retreat (G2), so its Master folio can settle.
     */
    public static function checkOutRetreat(Reservation $retreat): void
    {
        foreach ($retreat->units()->orderBy('id')->get() as $unit) {
            $result = app(EarlyCheckOut::class)(auth()->user(), $unit, reason: 'The retreat ended early.', idempotencyKey: (string) Str::uuid());

            if ($result->isRefused()) {
                throw new RuntimeException("Early check-out was refused: {$result->refusal}.");
            }
        }
    }

    /**
     * The company paid part of the stay at the hotel, in cash, before the folio went to the city ledger.
     */
    public static function advance(Property $property, Reservation $reservation, string $amount): void
    {
        $result = app(RecordPayment::class)(auth()->user(), $reservation, self::masterFolio($reservation), null, new NewPaymentData(self::method($property, PaymentMethodType::Cash)->id, PaymentPurpose::Settlement, $amount, null, (string) Str::uuid()));

        if ($result->isRefused()) {
            throw new RuntimeException("The advance was refused: {$result->refusal}.");
        }
    }

    public static function settle(Folio $folio): ArInvoice
    {
        $result = app(SettleToCityLedger::class)(auth()->user(), $folio);

        if ($result->isRefused()) {
            throw new RuntimeException("Settle was refused: {$result->refusal}.");
        }

        return $result->invoice;
    }

    /**
     * Check out the retreat and settle its Master folio: the first invoice of the company.
     */
    public static function settleRetreat(Reservation $retreat): ArInvoice
    {
        self::checkOutRetreat($retreat);

        return self::settle(self::masterFolio($retreat));
    }

    /**
     * A late charge on a third-party folio of the company, settled to the city ledger: a second invoice.
     */
    public static function lateCharge(Reservation $retreat, string $amount): ArInvoice
    {
        $folio = app(CreateFolio::class)->thirdParty($retreat, null, $retreat->company_id, auth()->user());
        FolioLine::factory()->create(['folio_id' => $folio->id, 'description' => 'Minibar', 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => '2026-10-14']);

        return self::settle($folio);
    }

    public static function credit(ArInvoice $invoice, string $amount, string $reason): ArInvoice
    {
        $result = app(IssueCreditNote::class)(auth()->user(), $invoice, $amount, $reason);

        if ($result->isRefused()) {
            throw new RuntimeException("The credit note was refused: {$result->refusal}.");
        }

        return $result->invoice;
    }

    public static function pay(Company $company, PaymentMethodType $type, string $amount, ?string $reference = null, bool $allocate = false, ?CarbonImmutable $receivedOn = null): ArPayment
    {
        $result = app(RecordArPayment::class)(auth()->user(), $company, new NewArPaymentData(self::method($company->property, $type)->id, $amount, (string) Str::uuid(), $receivedOn, $reference), $allocate);

        if ($result->isRefused()) {
            throw new RuntimeException("The payment was refused: {$result->refusal}.");
        }

        return $result->payment;
    }

    /**
     * @param  array<int, string>  $amounts  Amount by invoice id.
     */
    public static function allocate(ArPayment $payment, array $amounts): void
    {
        $result = app(AllocateArPayment::class)(auth()->user(), $payment, $amounts);

        if ($result->isRefused()) {
            throw new RuntimeException("The allocation was refused: {$result->refusal}.");
        }
    }

    /**
     * The retreat's Master folio (200.00 paid at the hotel, 1,517.96 left) and a late 85.00 charge, both settled: two
     * open invoices. The second invoice is back-dated by three days so the list shows an overdue due date; the clock
     * stays on the business date, and no Action moves a due date.
     *
     * @return array{0: ArInvoice, 1: ArInvoice}
     */
    public static function twoInvoices(Property $property, Reservation $retreat): array
    {
        self::advance($property, $retreat, '200.00');
        $first = self::settleRetreat($retreat);
        $second = self::lateCharge($retreat, '85.00');
        $second->update(['due_on' => '2026-10-11']);

        return [$first, $second->fresh()];
    }
}
