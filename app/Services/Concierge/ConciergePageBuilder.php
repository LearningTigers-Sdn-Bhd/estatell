<?php

namespace App\Services\Concierge;

use App\Actions\Concierge\RequestConciergeCheckOut;
use App\Data\Concierge\ConciergePinData;
use App\Data\Concierge\ConciergeRequestData;
use App\Data\Concierge\ConciergeStayData;
use App\Data\Concierge\ConciergeTermsData;
use App\Data\Property\PropertySummaryData;
use App\Enums\ConciergeStep;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\Locale;
use App\Models\GuestRequest;
use App\Models\ReservationUnit;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Kiosk\KioskPin;
use App\Services\Kiosk\KioskRegistrationTerms;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Support\Str;

/**
 * Builds the page for a stay and its step. It reads only. A card step with no published terms is shown as `Desk`,
 * because the guest could not sign. The room and the PIN are read only on the key step.
 */
class ConciergePageBuilder
{
    public function __construct(
        private ConciergeProgress $progress,
        private KioskStays $stays,
        private KioskPin $pin,
        private KioskRegistrationTerms $terms,
        private SelfCheckInPayment $payment,
        private RegistrationCardFieldRules $fieldRules,
        private ConciergeDocuments $documents,
        private RequestConciergeCheckOut $checkOut,
    ) {}

    public function build(string $token, ReservationUnit $stay, ?Locale $locale): ConciergeStayData
    {
        $stay->loadMissing('property.settings');

        $step = $this->progress->for($stay);
        $terms = null;

        if ($step === ConciergeStep::Card) {
            $resolved = $this->terms->resolve($stay, $locale);
            $terms = $resolved === null ? null : new ConciergeTermsData($resolved->terms->version, $this->terms->text($stay, $resolved), $resolved->locale->value);
            $step = $terms === null ? ConciergeStep::Desk : $step;
        }

        $guest = $this->stays->guest($stay);

        return new ConciergeStayData(
            token: $token,
            step: $step,
            property: PropertySummaryData::fromModel($stay->property),
            guestFirstName: $guest === null ? '' : Str::of($guest->full_name)->trim()->before(' ')->toString(),
            arrivalDate: $stay->arrival_date->toDateString(),
            departureDate: $stay->departure_date->toDateString(),
            reference: $stay->reservation()->firstOrFail()->reference,
            nights: (int) $stay->arrival_date->diffInDays($stay->departure_date),
            nationalityRequired: $guest !== null && blank($guest->nationality_code) && $this->fieldRules->nationalityIsNeededForTax($stay->property),
            roomNumber: $step === ConciergeStep::Key ? $this->stays->roomNumber($stay) : null,
            shortfall: $step === ConciergeStep::Payment ? $this->payment->shortfall($stay) : null,
            terms: $terms,
            pin: $step === ConciergeStep::Key ? ConciergePinData::fromStatus($this->pin->for($stay)) : null,
            documents: in_array($step, [ConciergeStep::Key, ConciergeStep::Home, ConciergeStep::CheckedOut], true) ? $this->documents->links($stay, $token) : [],
            canRequestRefund: $step === ConciergeStep::CheckedOut && $this->canRequestRefund($stay),
            checkOutAsked: in_array($step, [ConciergeStep::Key, ConciergeStep::Home], true) && $this->checkOut->open($stay) !== null,
            refundRequest: $step === ConciergeStep::CheckedOut ? $this->refundRequest($stay) : null,
        );
    }

    /**
     * Whether the page offers the refund form: the property has the switch on and the stay has no refund request still open.
     */
    private function canRequestRefund(ReservationUnit $stay): bool
    {
        return $stay->property->settings->concierge_refund_requests_enabled
            && ! GuestRequest::query()
                ->where('property_id', $stay->property_id)
                ->where('reservation_unit_id', $stay->id)
                ->where('type', GuestRequestType::Refund)
                ->whereIn('status', [GuestRequestStatus::Open, GuestRequestStatus::InProgress])
                ->exists();
    }

    /**
     * The guest's latest refund request, so they can see how it is going after they have checked out.
     */
    private function refundRequest(ReservationUnit $stay): ?ConciergeRequestData
    {
        $request = GuestRequest::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->where('type', GuestRequestType::Refund)
            ->latest('opened_at')
            ->first();

        return $request === null ? null : ConciergeRequestData::fromModel($request);
    }
}
