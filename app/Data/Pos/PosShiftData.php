<?php

namespace App\Data\Pos;

use App\Enums\PosShiftStatus;
use App\Models\PosShift;
use Spatie\LaravelData\Data;

/**
 * A register shift. The expected cash and the variance stay hidden until the shift is closed, so a blind count is blind
 * (`$reveal` is for the finance report, which may see them at any time).
 * `zReport` is the frozen Z-report once closed.
 */
class PosShiftData extends Data
{
    /**
     * @param  array<string, mixed>|null  $zReport
     */
    public function __construct(
        public int $id,
        public string $shiftNumber,
        public string $status,
        public string $statusLabel,
        public string $outletName,
        public string $registerName,
        public string $openedBy,
        public string $openedAt,
        public ?string $closedAt,
        public string $openingCash,
        public ?string $expectedCash,
        public ?string $countedCash,
        public ?string $cashVariance,
        public int $recountCount,
        public ?array $zReport,
    ) {}

    /**
     * Expects `outlet`, `register` and `openedBy` loaded.
     */
    public static function fromModel(PosShift $shift, bool $reveal = false): self
    {
        $closed = $reveal || $shift->status === PosShiftStatus::Closed;

        return new self(
            $shift->id,
            $shift->shift_number,
            $shift->status->value,
            $shift->status->label(),
            $shift->outlet->name,
            $shift->register->name,
            $shift->openedBy->name,
            $shift->opened_at->toIso8601String(),
            $shift->closed_at?->toIso8601String(),
            $shift->opening_cash,
            $closed ? $shift->expected_cash : null,
            $closed ? $shift->counted_cash : null,
            $closed ? $shift->cash_variance : null,
            $shift->recount_count,
            $closed ? $shift->z_report_snapshot : null,
        );
    }
}
