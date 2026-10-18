<?php

namespace App\Actions\Portal;

use App\Enums\CompanyStatus;
use App\Enums\PortalCancelMode;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\PortalCancellationRequested;
use App\Services\Portal\PortalBookingRules;
use Illuminate\Support\Facades\DB;

class RequestPortalCancellation
{
    public function __construct(private PortalBookingRules $rules, private NotifyReservationStaff $notifyReservationStaff) {}

    /**
     * Ask the property to cancel, after the free cancellation cut-off or when the company has none. It sets
     * `cancellation_requested_at` and `cancellation_requested_by` once, logs `reservation.cancellation_requested` and
     * notifies the reservation staff after the save. A second request, a request inside the free window (the company
     * cancels itself), after check-in, or from a Suspended company is refused and writes nothing. Staff resolve it in
     * the Cancel reservation Dialog. See docs/23-company-portal/01-company-portal.md#bookings.
     */
    public function __invoke(User $user, CompanyUser $link, Reservation $reservation): PortalBookingResult
    {
        $company = Company::query()->where('property_id', $link->property_id)->findOrFail($link->company_id);

        if ($company->status === CompanyStatus::Suspended) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_COMPANY_SUSPENDED);
        }

        $result = DB::transaction(function () use ($user, $link, $company, $reservation): PortalBookingResult {
            $locked = Reservation::query()->lockForUpdate()->where('property_id', $link->property_id)->where('company_id', $company->id)->findOrFail($reservation->id);
            $locked->load('units');

            if ($locked->cancellation_requested_at !== null) {
                return PortalBookingResult::refused(PortalBookingResult::REFUSAL_ALREADY_REQUESTED);
            }

            if ($this->rules->cancelMode($locked, $company, $this->rules->businessDate($link->property)) !== PortalCancelMode::Request) {
                return PortalBookingResult::refused(PortalBookingResult::REFUSAL_NOT_CANCELLABLE);
            }

            $locked->forceFill(['cancellation_requested_at' => now(), 'cancellation_requested_by' => $user->id])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->event('reservation.cancellation_requested')
                ->withProperties(['property_id' => $locked->property_id, 'company_id' => $company->id])
                ->log('Company asked to cancel the reservation');

            return PortalBookingResult::done($locked);
        });

        if (! $result->isRefused()) {
            ($this->notifyReservationStaff)($result->reservation, PortalCancellationRequested::class);
        }

        return $result;
    }
}
