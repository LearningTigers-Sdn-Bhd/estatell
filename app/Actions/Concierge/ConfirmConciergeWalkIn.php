<?php

namespace App\Actions\Concierge;

use App\Actions\Kiosk\AnnounceWalkInBooking;
use App\Data\Kiosk\KioskIdentityData;
use App\Data\Kiosk\KioskWalkInData;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Concierge\ReturningGuest;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Registration\RegistrationCardFieldRules;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Books a Concierge walk-in only once the guest has given who they are, so a booking never exists without a name. The guest picks a
 * room, fills in their details on a page that has no booking behind it, and this action then books and holds the room and saves the
 * details in one transaction: if any step says no, nothing is written. The room is therefore not held while the guest types, and
 * `BookConciergeWalkIn` checks it is still free at this moment. Either the guest typed their ID (`withIdentity`), or gave the email or
 * phone of a returning guest and the room is booked for that profile (`returning`). A stay that owes nothing is announced at once, as a paid one
 * is on its first payment (`AnnounceWalkInBooking`).
 */
class ConfirmConciergeWalkIn
{
    public function __construct(
        private BookConciergeWalkIn $book,
        private SaveConciergeIdentity $saveIdentity,
        private ReturningGuest $returning,
        private SelfCheckInPayment $payment,
        private AnnounceWalkInBooking $announce,
        private RegistrationCardFieldRules $fieldRules,
    ) {}

    /**
     * A quote link passes its `$ratePlanId` and `$quoted` (see `BookConciergeWalkIn`); a walk-in leaves them out.
     */
    public function withIdentity(Property $property, KioskWalkInData $search, int $roomTypeId, KioskIdentityData $data, ?UploadedFile $front = null, ?UploadedFile $back = null, ?int $ratePlanId = null, bool $quoted = false): ConciergeWalkInBooked
    {
        if (blank($data->nationalityCode) && $this->fieldRules->nationalityIsNeededForTax($property)) {
            return ConciergeWalkInBooked::refused('nationality_required');
        }

        return $this->confirm($property, $search, $roomTypeId, $ratePlanId, $quoted, null, fn (ReservationUnit $stay): ?string => ($this->saveIdentity)($stay, $data, $front, $back)->accepted ? null : 'identity_refused');
    }

    /**
     * A returning guest: the room is booked for the one profile that has this email or phone, so no second guest is made. Nothing is
     * booked when no profile matches.
     */
    public function returning(Property $property, KioskWalkInData $search, int $roomTypeId, string $contact, ?int $ratePlanId = null, bool $quoted = false): ConciergeWalkInBooked
    {
        $guest = $this->returning->match($property, $contact);

        if ($guest === null) {
            return ConciergeWalkInBooked::refused('no_match');
        }

        return $this->confirm($property, $search, $roomTypeId, $ratePlanId, $quoted, $guest, function (ReservationUnit $stay) use ($guest): ?string {
            activity()
                ->performedOn($guest)
                ->event('concierge.returning_guest_joined')
                ->withProperties(['property_id' => $stay->property_id, 'reservation_unit_id' => $stay->id])
                ->log('Walk-in booked for a returning guest by email or phone');

            return null;
        });
    }

    /**
     * @param  Closure(ReservationUnit): ?string  $fill  Runs on the held stay, and returns the refusal when it cannot go on.
     */
    private function confirm(Property $property, KioskWalkInData $search, int $roomTypeId, ?int $ratePlanId, bool $quoted, ?Guest $guest, Closure $fill): ConciergeWalkInBooked
    {
        try {
            $stay = DB::transaction(function () use ($property, $search, $roomTypeId, $ratePlanId, $quoted, $guest, $fill): ReservationUnit {
                $result = ($this->book)($property, $search, $roomTypeId, $guest, $ratePlanId, $quoted);

                if ($result->hold === null) {
                    throw new WalkInRefused($result->refusal ?? 'no_room_available');
                }

                $stay = $result->hold->stay()->firstOrFail();

                if (($refusal = $fill($stay)) !== null) {
                    throw new WalkInRefused($refusal);
                }

                // A quote stay pays the whole stay, so it is announced on its payment, never here.
                if (! $quoted && $this->payment->isPaidEnough($stay)) {
                    ($this->announce)($stay->property_id, $stay->reservation_id);
                }

                return $stay;
            });
        } catch (WalkInRefused $refused) {
            return ConciergeWalkInBooked::refused($refused->refusal);
        }

        return ConciergeWalkInBooked::booked($stay);
    }
}
