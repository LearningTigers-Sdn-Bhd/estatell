<?php

namespace App\Data\Reservations;

use App\Enums\BillingMode;
use App\Enums\CollectedTaxesPayer;
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
     * @param  array<string, string>  $remarks  The remark of each `RemarkKind` value that has one.
     */
    public function __construct(
        public int $bookerGuestId,
        #[LiteralTypeScriptType('{ id: number, name: string }')]
        public BookingSource $bookingSource,
        public array $units,
        public BillingMode $billingMode = BillingMode::MasterPays,
        public ?CollectedTaxesPayer $collectedTaxesPayer = null,
        public ?string $externalReference = null,
        public ?int $companyId = null,
        public ?string $groupName = null,
        public ?string $notes = null,
        public ?string $creditOverrideReason = null,
        public ?int $salesPersonId = null,
        public ?int $marketSegmentId = null,
        #[LiteralTypeScriptType('Partial<Record<App.Enums.RemarkKind, string>>')]
        public array $remarks = [],
    ) {}
}
