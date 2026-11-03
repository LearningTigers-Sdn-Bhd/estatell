<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One tile of the House status report: a room on the date, in the group (boat time, room move, stay-over,
 * management) it belongs to. The property names are the column keys of `ReportKey::HouseStatus`. `kind` (out, in,
 * move_out, move_in, occ, management) sets its colour, `group_key` and `group_order` its group. `checked_out` strikes
 * a room that already left. `key` is `{kind}:{unit or block id}`.
 */
class HouseStatusRow extends Data implements ReportRow
{
    /**
     * @param  list<string>  $flags
     * @param  list<int|string>  $group_order
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $room,
        public string $label,
        public int $pax,
        public array $flags,
        public ?string $remark,
        public string $kind,
        public string $group_key,
        public array $group_order,
        public bool $checked_out = false,
        public bool $warning = false,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'group' => $this->group,
            'room' => $this->room,
            'label' => $this->label,
            'pax' => $this->pax === 0 ? '' : 'x'.$this->pax,
            'flags' => implode(', ', $this->flags),
            'remark' => $this->remark ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'group' => $this->group,
            'room' => $this->room,
            'label' => $this->label,
            'pax' => $this->pax,
            'flags' => implode(', ', $this->flags),
            'remark' => $this->remark ?? '',
        ];
    }
}
