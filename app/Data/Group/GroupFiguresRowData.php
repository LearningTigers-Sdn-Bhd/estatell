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
     * The CSV headings, the first being the tab's row label (Property or Date).
     *
     * @return list<string>
     */
    public static function csvHeadings(string $first): array
    {
        return [$first, __('Available'), __('Sold'), __('Complimentary'), __('Occupancy %'), __('ADR'), __('RevPAR'), __('Pax'), __('Room charges'), __('Extra charges'), __('Tax'), __('Receipts')];
    }

    /**
     * @return list<string|int|null>
     */
    public function csv(): array
    {
        return [$this->label, $this->available, $this->sold, $this->complimentary, $this->occupancy, $this->adr, $this->revpar, $this->pax, $this->roomCharges, $this->extraCharges, $this->tax, $this->receipts];
    }
}
