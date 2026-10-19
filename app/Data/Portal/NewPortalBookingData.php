<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * What a company user sends to book: the dates, up to 20 lines, one lead guest for each unit, the optional group name
 * and company reference, and the total the page showed. `expectedTotal` is a decimal string; the server refuses the
 * booking when its own total differs.
 */
class NewPortalBookingData extends Data
{
    /**
     * @param  list<NewPortalLineData>  $lines
     * @param  list<NewPortalGuestData>  $guests
     */
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $arrivalDate,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $departureDate,
        public array $lines,
        public array $guests,
        public ?string $groupName,
        public ?string $externalReference,
        public string $expectedTotal,
    ) {}
}
