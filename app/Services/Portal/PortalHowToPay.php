<?php

namespace App\Services\Portal;

use App\Data\Catalogues\BankAccountData;
use App\Data\Payments\PaymentProofData;
use App\Data\Portal\PortalHowToPayData;
use App\Data\Reservations\AgentBillData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\CompanyStatus;
use App\Enums\ReservationStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\PropertyBankAccount;
use App\Models\Reservation;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\Reservations\AgentPaymentSchedule;
use Brick\Math\BigDecimal;

/**
 * How a company pays one booking by bank transfer, for the portal Payments tab. Every company can send a proof while
 * money is left to pay: on the booking while a folio the company pays is open, and for the booking's invoice once it is
 * invoiced (direct billing). See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class PortalHowToPay
{
    public function __construct(private AgentPaymentSchedule $schedule, private ArInvoiceBalance $invoiceBalance) {}

    public function for(Property $property, Company $company, Reservation $booking, AgentBillData $bill): PortalHowToPayData
    {
        $invoice = $this->acceptsProof($booking) ? null : $this->openInvoice($company, $booking);

        if ($invoice === null) {
            $paid = $this->schedule->paid($booking);
            $left = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($bill->agentTotal)->minus($paid));
            $canSend = $this->acceptsProof($booking);
        } else {
            $left = BigDecimal::of($this->invoiceBalance->forInvoice($invoice));
            $paid = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($bill->agentTotal)->minus($left));
            $canSend = true;
        }

        return new PortalHowToPayData(
            billedLater: $this->billedLater($company),
            paid: (string) $paid->toScale(2),
            leftToPay: (string) $left->toScale(2),
            bankAccounts: $this->bankAccounts($property),
            canSendProof: $canSend && $company->status !== CompanyStatus::Suspended && $left->isPositive(),
            contactPhone: $property->contact_phone,
            contactEmail: $property->contact_email,
            invoiceNumber: $invoice?->number,
            invoiceDueOn: $invoice?->due_on?->toDateString(),
        );
    }

    /**
     * The available accounts the company can pay into, in order. The Statements slip form shows them too.
     *
     * @return list<BankAccountData>
     */
    public function bankAccounts(Property $property): array
    {
        return array_values($property->bankAccounts()->active()->ordered()->get()
            ->map(fn (PropertyBankAccount $account): BankAccountData => BankAccountData::fromModel($account))
            ->all());
    }

    /** A direct-billing company is invoiced after the stay. It can still pay the booking before or after that. */
    public function billedLater(Company $company): bool
    {
        return $company->direct_bill_enabled;
    }

    /**
     * Whether the booking still takes a proof: not cancelled, and a completed one only while a folio the company pays is
     * still open, so an agent can still pay after the guests leave.
     */
    public function acceptsProof(Reservation $booking): bool
    {
        return $booking->status !== ReservationStatus::Cancelled
            && ($booking->status !== ReservationStatus::Completed || Reservation::query()->whereKey($booking->id)->agentPaymentsOpen()->exists());
    }

    /**
     * The booking's oldest open invoice to the company, once a folio it pays was settled to the city ledger. A proof
     * for it is a company slip that names the invoice.
     */
    public function openInvoice(Company $company, Reservation $booking): ?ArInvoice
    {
        return ArInvoice::query()
            ->where('company_id', $company->id)
            ->where('type', ArInvoiceType::Invoice)
            ->where('status', ArInvoiceStatus::Open)
            ->whereHas('folio', fn ($folios) => $folios->where('reservation_id', $booking->id))
            ->orderBy('due_on')
            ->orderBy('id')
            ->first();
    }

    /**
     * The proofs sent for the booking, newest first: those on the reservation and the company slips for its invoices.
     * Each links to its own file route.
     *
     * @return list<PaymentProofData>
     */
    public function proofs(Property $property, Company $company, Reservation $booking): array
    {
        return array_values(PaymentProof::query()
            ->where(fn ($proofs) => $proofs
                ->where('reservation_id', $booking->id)
                ->orWhere(fn ($slips) => $slips
                    ->where('company_id', $company->id)
                    ->whereHas('arInvoice.folio', fn ($folios) => $folios->where('reservation_id', $booking->id))))
            ->with(['uploader', 'bankAccount', 'arInvoice'])
            ->latest('id')
            ->get()
            ->map(fn (PaymentProof $proof): PaymentProofData => PaymentProofData::fromModel($proof, $proof->reservation_id === null
                ? route('portal.statements.payment-slips.file', ['property' => $property->code, 'paymentProof' => $proof->id], absolute: false)
                : route('portal.bookings.payment-proofs.file', ['property' => $property->code, 'reservation' => $booking->id, 'paymentProof' => $proof->id], absolute: false)))
            ->all());
    }
}
