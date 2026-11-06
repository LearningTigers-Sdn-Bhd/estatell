<?php

namespace App\Data\Platform;

use App\Enums\BookingEngineMode;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * The property's booking engine mode on the Features tab, shown under the Booking engine switch. A property whose
 * booking site was never opened is in Microsite mode.
 */
class BookingEngineModeData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public BookingEngineMode $mode,
        public array $options,
    ) {}

    public static function forProperty(Property $property): self
    {
        return new self(
            mode: $property->bookingSite()->first()->mode ?? BookingEngineMode::Microsite,
            options: BookingEngineMode::options(),
        );
    }
}
