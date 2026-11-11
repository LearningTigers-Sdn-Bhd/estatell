<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Housekeeping items report: one item a room needs on the date (an extra bed). The property names are
 * the column keys of `ReportKey::HousekeepingItems`. `key` is the unit id and the charge item id (`41:7`). `action` is
 * Set up (first night with the item), In room, or Remove (the day after its last night).
 */
class HousekeepingItemRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $room,
        public string $guest,
        public int $pax,
        public string $item,
        public int $quantity,
        public string $action,
        public string $arrival,
        public string $departure,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'room' => $this->room,
            'guest' => $this->guest,
            'pax' => (string) $this->pax,
            'item' => $this->item,
            'quantity' => (string) $this->quantity,
            'action' => $this->action,
            'stay' => CarbonImmutable::parse($this->arrival)->format('j M').' – '.CarbonImmutable::parse($this->departure)->format('j M'),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'room' => $this->room,
            'guest' => $this->guest,
            'pax' => $this->pax,
            'item' => $this->item,
            'quantity' => $this->quantity,
            'action' => $this->action,
            'stay' => $this->arrival,
        ];
    }
}
