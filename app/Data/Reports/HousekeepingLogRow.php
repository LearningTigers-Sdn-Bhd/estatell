<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One cleaning in the Housekeeping log. The property names are the column keys of `ReportKey::HousekeepingLog`. `key`
 * is the cleaning id. `in` and `out` are `H:i` in the property's time zone.
 */
class HousekeepingLogRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $date,
        public string $room,
        public string $room_type,
        public string $housekeeper,
        public string $in,
        public ?string $out,
        public ?int $minutes,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => $this->date,
            'room' => $this->room,
            'room_type' => $this->room_type,
            'housekeeper' => $this->housekeeper,
            'in' => $this->in,
            'out' => $this->out ?? '',
            'minutes' => $this->minutes === null ? '' : (string) $this->minutes,
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'room' => $this->room,
            'room_type' => $this->room_type,
            'housekeeper' => $this->housekeeper,
            'in' => $this->in,
            'out' => $this->out ?? '',
            'minutes' => $this->minutes ?? '',
        ];
    }
}
