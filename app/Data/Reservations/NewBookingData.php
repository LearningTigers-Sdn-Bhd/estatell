<?php

namespace App\Data\Reservations;

use App\Enums\BillingMode;
use App\Enums\ReservationSource;
use Spatie\LaravelData\Data;

/**
 * Everything the booking form saves: the reservation fields and lines of `NewReservationData`, minus the booker (the
 * first guest tab is the booker), plus the guest tabs and the room or bed picks.
 * See docs/12-reservations/05-booking-form.md.
 */
class NewBookingData extends Data
{
    /**
     * @param  list<NewUnitData>  $units
     * @param  list<NewBookingGuestData>  $guests
     * @param  list<UnitPickData>  $picks
     */
    public function __construct(
        public ReservationSource $source,
        public array $units,
        public array $guests,
        public array $picks = [],
        public BillingMode $billingMode = BillingMode::MasterPays,
        public ?string $channelName = null,
        public ?string $externalReference = null,
        public ?string $groupName = null,
        public ?string $notes = null,
        public ?int $companyId = null,
        public ?string $creditOverrideReason = null,
    ) {}
}
