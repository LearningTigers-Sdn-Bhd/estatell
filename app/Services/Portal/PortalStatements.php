<?php

namespace App\Services\Portal;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Data\CityLedger\StatementData;
use App\Data\Payments\PaymentProofData;
use App\Data\Portal\PortalPaymentRowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentProofStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\StatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the statements page of one company at one property: its Open and Settled AR documents, its payments that are
 * not void, and the statement of account from the staff city ledger. Every document is looked up by its id together
 * with the property and the company, so another company's or property's document is not found (404). It writes nothing.
 * See docs/23-company-portal/01-company-portal.md#statements.
 */
class PortalStatements
{
    public const string INVOICES_PAGE = 'invoices_page';

    public const string PAYMENTS_PAGE = 'payments_page';

    public function __construct(private StatementBuilder $statementBuilder, private ArInvoiceDirectory $directory) {}

    /**
     * The statement of account between two dates, with the aging as at the end date.
     */
    public function statement(Company $company, CarbonImmutable $from, CarbonImmutable $to): StatementData
    {
        return $this->statementBuilder->build($company, $from, $to);
    }

    /**
     * Whether the company can send a payment slip for its account: it is on direct billing, or it still has an open
     * invoice from when it was.
     */
    public function canSendSlip(Company $company): bool
    {
        return $company->direct_bill_enabled
            || ArInvoice::query()->where('company_id', $company->id)->where('status', ArInvoiceStatus::Open->value)->exists();
    }

    /**
     * The staff who check a company's slips: active, not platform admins, with `city-ledger.payment` at its property.
     *
     * @return Collection<int, User>
     */
    public function slipReviewers(Company $company): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($company->property_id);

        try {
            return User::query()
                ->where('property_id', $company->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission('city-ledger.payment')
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * How many of the company's slips wait for staff to check them, for the Slips tab label.
     */
    public function slipsWaiting(Company $company): int
    {
        return PaymentProof::query()->where('company_id', $company->id)->where('status', PaymentProofStatus::Submitted)->count();
    }

    /**
     * The slips the company sent for its account, newest first.
     *
     * @return list<PaymentProofData>
     */
    public function slips(Property $property, Company $company): array
    {
        return array_values(PaymentProof::query()
            ->where('company_id', $company->id)
            ->with(['uploader', 'bankAccount', 'arInvoice'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (PaymentProof $slip): PaymentProofData => PaymentProofData::fromModel($slip, route('portal.statements.payment-slips.file', ['property' => $property->code, 'paymentProof' => $slip->id])))
            ->all());
    }

    /**
     * @return LengthAwarePaginator<int, ArInvoiceRowData>
     */
    public function invoices(Property $property, Company $company): LengthAwarePaginator
    {
        $today = $this->directory->today($property);
        $figures = $this->directory->balanceFigureSubqueries();

        return ArInvoice::query()
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereIn('status', [ArInvoiceStatus::Open->value, ArInvoiceStatus::Settled->value])
            ->addSelect('ar_invoices.*')
            ->selectSub($figures['credited'], 'credited')
            ->selectSub($figures['allocated'], 'allocated')
            ->with('company:id,name')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->paginate(ArInvoiceDirectory::PER_PAGE, ['*'], self::INVOICES_PAGE)
            ->withQueryString()
            ->through(fn (ArInvoice $invoice): ArInvoiceRowData => $this->directory->row($invoice, $today));
    }

    /**
     * @return LengthAwarePaginator<int, PortalPaymentRowData>
     */
    public function payments(Property $property, Company $company): LengthAwarePaginator
    {
        return ArPayment::query()
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->with('paymentMethod:id,name')
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(ArInvoiceDirectory::PER_PAGE, ['*'], self::PAYMENTS_PAGE)
            ->withQueryString()
            ->through(fn (ArPayment $payment): PortalPaymentRowData => PortalPaymentRowData::fromModel($payment));
    }

    /**
     * One invoice or credit note the company can see. Void documents are not found.
     */
    public function invoice(Property $property, Company $company, int $invoiceId): ArInvoice
    {
        return ArInvoice::query()
            ->whereKey($invoiceId)
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereIn('status', [ArInvoiceStatus::Open->value, ArInvoiceStatus::Settled->value])
            ->firstOrFail();
    }

    /**
     * One payment the company can see. Voided payments are not found.
     */
    public function payment(Property $property, Company $company, int $paymentId): ArPayment
    {
        return ArPayment::query()
            ->whereKey($paymentId)
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->firstOrFail();
    }
}
