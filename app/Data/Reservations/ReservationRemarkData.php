<?php

namespace App\Data\Reservations;

use App\Enums\RemarkKind;
use App\Models\ReservationRemark;
use Spatie\LaravelData\Data;

/**
 * One remark of a booking on the reservation page. See docs/12-reservations/16-remarks.md.
 */
class ReservationRemarkData extends Data
{
    public function __construct(
        public RemarkKind $kind,
        public string $label,
        public string $body,
    ) {}

    public static function fromModel(ReservationRemark $remark): self
    {
        return new self($remark->kind, $remark->kind->label(), $remark->body);
    }
}
