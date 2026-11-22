<?php

namespace App\Data\Group;

use Spatie\LaravelData\Data;

/**
 * One row of Group reports › By property (a property, or the group total with `code` null) or › By date (a date in
 * `label`): the History & forecast figures, added up. Occupancy, ADR and RevPAR are worked out again from the sums.
 * Money is null in a total when the properties use different currencies. See docs/03-staff/05-group-mode.md.
 */
class GroupFiguresRowData extends Data
{
    public function __construct(
        public string $label,
        public ?string $code,
        public int $available,
        public int $sold,
        public int $complimentary,
        public string $occupancy,
        public ?string $adr,
        public ?string $revpar,
        public int $pax,
        public ?string $roomCharges,
        public ?string $extraCharges,
        public ?string $tax,
        public ?string $receipts,
    ) {}

    /**
     * @return array<string, string|int|float|null>
     */
    public function csv(): array
    {
        return [
            'label' => $this->label,
            'available' => $this->available,
            'sold' => $this->sold,
            'complimentary' => $this->complimentary,
            'occupancy' => $this->occupancy,
            'adr' => $this->adr,
            'revpar' => $this->revpar,
            'pax' => $this->pax,
            'room_charges' => $this->roomCharges,
            'extra_charges' => $this->extraCharges,
            'tax' => $this->tax,
            'receipts' => $this->receipts,
        ];
    }
}
