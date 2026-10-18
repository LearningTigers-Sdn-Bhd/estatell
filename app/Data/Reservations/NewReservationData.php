<?php

namespace App\Data\Reservations;

use App\Enums\BillingMode;
use App\Models\BookingSource;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * What `CreateReservation` needs: the booker, where the booking came from and one line for each room type and plan.
 * See docs/12-reservations/02-group-bookings.md.
 */
class NewReservationData extends Data
{
    /**
     * @param  list<NewUnitData>  $units
     */
    public function __construct(
        public int $bookerGuestId,
        #[LiteralTypeScriptType('{ id: number, name: string }')]
        public BookingSource $bookingSource,
        public array $units,
        public BillingMode $billingMode = BillingMode::MasterPays,
        public ?string $externalReference = null,
        public ?int $companyId = null,
        public ?string $groupName = null,
        public ?string $notes = null,
        public ?string $creditOverrideReason = null,
    ) {}
}
