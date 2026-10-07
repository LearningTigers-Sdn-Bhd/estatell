<?php

namespace App\Services\Reports;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\TransactionCode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Revenue by transaction code, read live from the folio ledger by posting date. Every revenue figure comes from
 * here: the live daily revenue report reads it for any range, and the night audit copies one closed date into
 * `daily_revenue`. A line counts on its posting date, so a reversal reduces the date it was posted on. Payment lines
 * are not revenue and are left out; charges, taxes and adjustments (discounts) are in. It reads only.
 * See docs/20-night-audit/05-statistics.md.
 */
class DailyRevenueQuery
{
    /**
     * One row for each date and transaction code that has lines in the range, oldest date first.
     *
     * @return Collection<int, DailyRevenueRow>
     */
    public function between(Property $property, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $currency = $this->currency($property);

        return $this->lines($property, $from, $to)
            ->where('folio_lines.kind', '!=', TransactionKind::Payment->value)
            ->groupBy('folio_lines.posting_date', 'folio_lines.transaction_code_id')
            ->orderBy('folio_lines.posting_date')
            ->orderBy('folio_lines.transaction_code_id')
            ->selectRaw('folio_lines.posting_date as date, folio_lines.transaction_code_id, SUM(folio_lines.amount) as amount, COUNT(*) as line_count')
            ->get()
            ->map(fn (FolioLine $row): DailyRevenueRow => new DailyRevenueRow(
                CarbonImmutable::parse((string) $row->getAttribute('date')),
                (int) $row->getAttribute('transaction_code_id'),
                Money::of((string) $row->getAttribute('amount'), $currency),
                (int) $row->getAttribute('line_count'),
            ))
            ->values();
    }

    /**
     * The rows of one date.
     *
     * @return Collection<int, DailyRevenueRow>
     */
    public function forDate(Property $property, CarbonInterface $date): Collection
    {
        return $this->between($property, $date, $date);
    }

    /**
     * The room revenue of one date, net of tax: the room revenue code plus the discount lines under its charges.
     * Zero when the property has no room revenue code.
     */
    public function roomRevenue(Property $property, CarbonInterface $date): Money
    {
        $currency = $this->currency($property);
        $roomCodeId = TransactionCode::query()
            ->where('property_id', $property->id)
            ->where('system_key', SystemTransactionCode::RoomRevenue->value)
            ->value('id');

        if ($roomCodeId === null) {
            return Money::zero($currency);
        }

        $amount = $this->lines($property, $date, $date)
            ->where('folio_lines.kind', '!=', TransactionKind::Payment->value)
            ->leftJoin('folio_lines as parent_lines', 'parent_lines.id', '=', 'folio_lines.parent_line_id')
            ->where(fn (Builder $lines) => $lines
                ->where('folio_lines.transaction_code_id', $roomCodeId)
                ->orWhere(fn (Builder $discounts) => $discounts
                    ->where('folio_lines.kind', TransactionKind::Adjustment->value)
                    ->where('parent_lines.transaction_code_id', $roomCodeId)))
            ->sum('folio_lines.amount');

        return Money::of((string) $amount, $currency);
    }

    /**
     * The ledger lines of the property posted from one date to another, both included.
     *
     * @return Builder<FolioLine>
     */
    private function lines(Property $property, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.property_id', $property->id)
            ->whereBetween('folio_lines.posting_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function currency(Property $property): string
    {
        return $property->loadMissing('settings')->settings->currency->value;
    }
}
