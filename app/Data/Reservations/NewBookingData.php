<?php

namespace App\Data\Reservations;

use App\Enums\BillingMode;
use App\Enums\CollectedTaxesPayer;
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
     * @param  array<string, string>  $remarks  The remark of each `RemarkKind` value that has one.
     */
    public function __construct(
        #[LiteralTypeScriptType('{ id: number, name: string }')]
        public BookingSource $bookingSource,
        public array $units,
        public array $guests,
        public array $picks = [],
        public BillingMode $billingMode = BillingMode::MasterPays,
        public ?CollectedTaxesPayer $collectedTaxesPayer = null,
        public ?string $externalReference = null,
        public ?string $groupName = null,
        public ?string $notes = null,
        public ?int $companyId = null,
        public ?string $creditOverrideReason = null,
        public ?int $salesPersonId = null,
        public ?int $marketSegmentId = null,
        #[LiteralTypeScriptType('Partial<Record<App.Enums.RemarkKind, string>>')]
        public array $remarks = [],
    ) {}
}
