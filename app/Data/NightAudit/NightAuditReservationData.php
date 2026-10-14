<?php

namespace App\Data\NightAudit;

use App\Data\Payments\PaymentFormOptionsData;
use App\Data\Property\CurrencyData;
use App\Data\Reservations\ReservationData;
use App\Data\Reservations\ReservationUnitData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationOverview;
use App\Services\Reservations\UnitActionMenu;
use Spatie\LaravelData\Data;

/**
 * What the check-in, check-in-all and check-out Dialogs need when the night audit sheet opens them: the reservation,
 * its units with the actions the user may use, and the payment options. It is the same data as the reservation page,
 * loaded only when a Dialog opens.
 */
class NightAuditReservationData extends Data
{
    /**
     * @param  list<ReservationUnitData>  $units
     */
    public function __construct(
        public ReservationData $reservation,
        public array $units,
        public ?PaymentFormOptionsData $paymentOptions,
        public bool $canWaiveFees,
        public bool $canAssignRoom,
        public ?CurrencyData $currency,
    ) {}

    public static function forReservation(Reservation $reservation, Property $property, User $user, ReservationOverview $overview, UnitActionMenu $actionMenu, ReservationFormOptions $options): self
    {
        $canViewGuest = $user->can(PermissionEnum::GuestsView->value);
        $reservation->load(['booker', 'creator', 'company']);
        $built = $overview->build($reservation, $canViewGuest);

        $units = $built['models']->map(function (ReservationUnit $unit) use ($user, $canViewGuest, $actionMenu): ReservationUnitData {
            $unit->load(['roomType', 'ratePlan', 'childAges', 'guests.guest', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.room', 'nights.bed', 'nights.ratePlan']);

            return ReservationUnitData::fromModel($unit, $canViewGuest, null, null, $actionMenu->for($user, $unit));
        })->values()->all();

        return new self(
            reservation: $built['reservation'],
            units: array_values($units),
            paymentOptions: $user->can(PermissionEnum::PaymentRecord->value) ? $options->payments($property) : null,
            canWaiveFees: $user->can(PermissionEnum::FrontDeskWaiveFees->value),
            canAssignRoom: $user->can(PermissionEnum::ReservationsManage->value),
            currency: CurrencyData::forProperty($property),
        );
    }
}
