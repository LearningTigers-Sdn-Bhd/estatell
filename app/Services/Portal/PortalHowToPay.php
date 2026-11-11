<?php

namespace App\Services\Portal;

use App\Data\Catalogues\BankAccountData;
use App\Data\Portal\PortalHowToPayData;
use App\Data\Reservations\AgentBillData;
use App\Enums\CompanyStatus;
use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertyBankAccount;
use App\Models\Reservation;
use App\Services\Reservations\AgentPaymentSchedule;
use Brick\Math\BigDecimal;

/**
 * How a company pays one booking by bank transfer, for the portal Payments tab. A company that pays per booking (no
 * direct billing) sends a proof while money is left to pay; a direct-billing company pays its invoice from Statements.
 * See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class PortalHowToPay
{
    public function __construct(private AgentPaymentSchedule $schedule) {}

    public function for(Property $property, Company $company, Reservation $booking, AgentBillData $bill): PortalHowToPayData
    {
        $paid = $this->schedule->paid($booking);
        $left = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($bill->agentTotal)->minus($paid));

        return new PortalHowToPayData(
            billedLater: $this->billedLater($company),
            paid: (string) $paid->toScale(2),
            leftToPay: (string) $left->toScale(2),
            bankAccounts: $this->bankAccounts($property),
            canSendProof: ! $this->billedLater($company)
                && $company->status !== CompanyStatus::Suspended
                && $left->isPositive()
                && $this->acceptsProof($booking),
            contactPhone: $property->contact_phone,
            contactEmail: $property->contact_email,
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

    /** A direct-billing company is invoiced after the stay, so it sends no proof on a booking. */
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
}
