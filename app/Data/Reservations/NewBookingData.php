<?php

namespace App\Data\Reservations;

use App\Enums\BillingMode;
use App\Models\BookingSource;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

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
        #[LiteralTypeScriptType('{ id: number, name: string }')]
        public BookingSource $bookingSource,
        public array $units,
        public array $guests,
        public array $picks = [],
        public BillingMode $billingMode = BillingMode::MasterPays,
        public ?string $externalReference = null,
        public ?string $groupName = null,
        public ?string $notes = null,
        public ?int $companyId = null,
        public ?string $creditOverrideReason = null,
    ) {}
}
