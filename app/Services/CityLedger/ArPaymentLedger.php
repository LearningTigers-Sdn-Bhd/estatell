<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\ArPaymentMethodOptionData;
use App\Data\CityLedger\ArPaymentRowData;
use App\Data\CityLedger\OpenArInvoiceData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * Reads the Payments tab of a company: its payments newest first with what is left to allocate, the invoices that
 * still owe money, and the payment methods staff can record with. Unallocated amounts and allocation counts come
 * from one grouped query, so the tab runs a constant number of queries. It writes nothing.
 * See docs/15-city-ledger/03-ar-payments.md#pages.
 */
class ArPaymentLedger
{
    public function __construct(private ArInvoiceBalance $arInvoiceBalance) {}

    /**
     * @return list<ArPaymentRowData>
     */
    public function payments(Company $company, User $user): array
    {
        $payments = ArPayment::query()
            ->where('company_id', $company->id)
            ->with('paymentMethod:id,name')
            ->latest('received_on')
            ->latest('id')
            ->get();

        $allocated = ArAllocation::query()
            ->whereIn('ar_payment_id', $payments->pluck('id'))
            ->active()
            ->groupBy('ar_payment_id')
            ->selectRaw('ar_payment_id, SUM(amount) AS allocated')
            ->pluck('allocated', 'ar_payment_id');

        $mayAllocate = $user->can(PermissionEnum::CityLedgerAllocate->value);
        $mayVoid = $user->can(PermissionEnum::CityLedgerPayment->value);

        return array_values($payments->map(function (ArPayment $payment) use ($allocated, $mayAllocate, $mayVoid): ArPaymentRowData {
            $used = (string) ($allocated[$payment->id] ?? '0');
            $unallocated = $payment->voided_at !== null ? '0.00' : (string) BigDecimal::of((string) $payment->amount)->minus($used)->toScale(2);

            return ArPaymentRowData::fromModel($payment, $unallocated, BigDecimal::of($used)->isPositive(), $mayAllocate, $mayVoid);
        })->all());
    }

    /**
     * The company's Open invoices that owe money, oldest due date first.
     *
     * @return list<OpenArInvoiceData>
     */
    public function openInvoices(Company $company): array
    {
        return array_values(ArInvoice::query()
            ->where('ar_invoices.company_id', $company->id)
            ->where('ar_invoices.type', ArInvoiceType::Invoice)
            ->where('ar_invoices.status', ArInvoiceStatus::Open)
            ->addSelect('ar_invoices.*')
            ->selectSub(
                ArInvoice::query()
                    ->from('ar_invoices as credits')
                    ->selectRaw('COALESCE(SUM(credits.amount), 0)')
                    ->whereColumn('credits.credit_for_invoice_id', 'ar_invoices.id')
                    ->where('credits.status', '<>', ArInvoiceStatus::Void->value),
                'credited',
            )
            ->selectSub(
                ArAllocation::query()
                    ->selectRaw('COALESCE(SUM(ar_allocations.amount), 0)')
                    ->whereColumn('ar_allocations.ar_invoice_id', 'ar_invoices.id')
                    ->whereNull('ar_allocations.reversed_at'),
                'allocated',
            )
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->map(fn (ArInvoice $invoice): array => [$invoice, $this->arInvoiceBalance->fromFigures((string) $invoice->amount, (string) $invoice->getAttribute('credited'), (string) $invoice->getAttribute('allocated'))])
            ->filter(fn (array $pair): bool => BigDecimal::of($pair[1])->isPositive())
            ->map(fn (array $pair): OpenArInvoiceData => OpenArInvoiceData::fromModel($pair[0], $pair[1]))
            ->values()
            ->all());
    }

    /**
     * Active methods of the property except OTA collected and City ledger, in the catalogue order.
     *
     * @return list<ArPaymentMethodOptionData>
     */
    public function methods(Company $company): array
    {
        return array_values(PaymentMethod::query()
            ->where('property_id', $company->property_id)
            ->active()
            ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(ArPaymentMethodOptionData::fromModel(...))
            ->all());
    }
}
