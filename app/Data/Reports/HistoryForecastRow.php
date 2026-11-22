<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of History & forecast: one date. The property names are the column keys of `ReportKey::HistoryForecast`.
 * `key` is the date. `available`, `sold` and `complimentary` count rooms and beds as one unit each. `receipts` is null
 * for a forecast date, which has none yet. See docs/22-reports/04-history-and-forecast.md.
 */
class HistoryForecastRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public BadgeData $basis,
        public int $available,
        public int $sold,
        public int $complimentary,
        public string $occupancy,
        public string $adr,
        public string $revpar,
        public int $pax,
        public string $room_charges,
        public string $extra_charges,
        public string $tax,
        public ?string $receipts,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('D j M Y'),
            'basis' => $this->basis->label,
            'available' => (string) $this->available,
            'sold' => (string) $this->sold,
            'complimentary' => (string) $this->complimentary,
            'occupancy' => number_format((float) $this->occupancy, 2),
            'adr' => number_format((float) $this->adr, 2),
            'revpar' => number_format((float) $this->revpar, 2),
            'pax' => (string) $this->pax,
            'room_charges' => number_format((float) $this->room_charges, 2),
            'extra_charges' => number_format((float) $this->extra_charges, 2),
            'tax' => number_format((float) $this->tax, 2),
            'receipts' => $this->receipts === null ? '—' : number_format((float) $this->receipts, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'basis' => $this->basis->label,
            'available' => $this->available,
            'sold' => $this->sold,
            'complimentary' => $this->complimentary,
            'occupancy' => round((float) $this->occupancy, 2),
            'adr' => round((float) $this->adr, 2),
            'revpar' => round((float) $this->revpar, 2),
            'pax' => $this->pax,
            'room_charges' => round((float) $this->room_charges, 2),
            'extra_charges' => round((float) $this->extra_charges, 2),
            'tax' => round((float) $this->tax, 2),
            'receipts' => $this->receipts === null ? '' : round((float) $this->receipts, 2),
        ];
    }
}
