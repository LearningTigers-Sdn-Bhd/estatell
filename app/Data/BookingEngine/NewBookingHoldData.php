<?php

namespace App\Data\BookingEngine;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * What a guest chose at checkout. `total` is the online total they saw, as a decimal string. `guestIp` is the raw
 * `X-Guest-IP` header.
 */
class NewBookingHoldData extends Data
{
    /**
     * @param  list<NewBookingHoldLineData>  $lines
     */
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $arrival,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $departure,
        public string $total,
        public string $guestName,
        public string $guestEmail,
        public string $guestPhone,
        public string $guestNationality,
        public ?string $specialRequests,
        public array $lines,
        public ?string $guestIp,
    ) {}
}
