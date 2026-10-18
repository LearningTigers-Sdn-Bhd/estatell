<?php

namespace App\Data\Reservations;

use App\Enums\SwatchColor;
use App\Models\OtaChannel;
use Spatie\LaravelData\Data;

/**
 * One OTA channel in the booking source Dialog, with the color a new source on it starts with.
 */
class BookingSourceChannelOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public bool $isOther,
        public SwatchColor $defaultColor,
    ) {}

    public static function fromModel(OtaChannel $channel): self
    {
        return new self(
            id: $channel->id,
            code: $channel->code,
            name: $channel->name,
            isOther: $channel->is_other,
            defaultColor: SwatchColor::forOtaChannelCode($channel->code),
        );
    }
}
