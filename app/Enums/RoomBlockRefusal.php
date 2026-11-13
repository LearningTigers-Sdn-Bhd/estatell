<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Why a room block was not created or saved. The Actions return it instead of throwing.
 */
enum RoomBlockRefusal: string
{
    case Unavailable = 'unavailable';
    case Ended = 'ended';
    case Blocked = 'blocked';
    case HasStay = 'has_stay';

    /**
     * @param  string  $unit  "Room 203" or "Room 12, bed A3".
     * @param  CarbonInterface|null  $from  Blocked: the first night of the other block. HasStay: the first night of the stay.
     * @param  CarbonInterface|null  $to  Blocked: the last night of the other block.
     * @param  string|null  $reference  HasStay: the booking reference of the stay.
     */
    public function message(string $unit, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $reference = null): string
    {
        return match ($this) {
            self::Unavailable => __(':unit is no longer active.', ['unit' => $unit]),
            self::Ended => __('Block for :unit was not saved. It has ended.', ['unit' => $unit]),
            self::Blocked => $from !== null && $to !== null
                ? __(':unit is already blocked from :from to :to. To change those nights, edit that block.', [
                    'unit' => $unit, 'from' => $from->format('j M Y'), 'to' => $to->format('j M Y'),
                ])
                : __(':unit is already blocked for some of these nights.', ['unit' => $unit]),
            self::HasStay => $from !== null && $reference !== null
                ? __(':unit has a stay on :date (booking :reference). Move the stay first.', [
                    'unit' => $unit, 'date' => $from->format('j M Y'), 'reference' => $reference,
                ])
                : __(':unit has a stay on some of these nights. Move the stay first.', ['unit' => $unit]),
        };
    }
}
