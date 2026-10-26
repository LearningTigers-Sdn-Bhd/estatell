<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of Daily report › Charges: one owner line on one posting date, with its inline discount, its later
 * discounts without tax and its tax lines of that date. The property names are the column keys of
 * `ReportKey::DailyCharges`; a tax column `tax:{code}` reads `taxes[code]`. `key` is a line id: the owner's id on the
 * owner's own date, else the lowest line id of the row. `bucket` is the label of the owner's charge bucket, or an empty
 * text for a line that has none. `date` is the posting date (`Y-m-d`). Money is signed, as in the ledger.
 */
class DailyChargeRow extends Data implements ReportRow
{
    /**
     * @param  array<string, string>  $taxes  The tax amount for each tax code of the property.
     */
    public function __construct(
        public int $key,
        public string $date,
        public string $charge,
        public string $booking,
        public string $guest,
        public string $base,
        public string $discount,
        public array $taxes,
        public string $total,
        public string $bucket,
        public string $folio,
        public string $room,
        public string $code,
        public BadgeData $status,
        public ?string $staff,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        $taxes = [];

        foreach ($this->taxes as $code => $amount) {
            $taxes["tax:{$code}"] = (float) $amount === 0.0 ? '—' : number_format((float) $amount, 2);
        }

        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'charge' => $this->charge,
            'booking' => $this->booking,
            'guest' => $this->guest,
            'base' => number_format((float) $this->base, 2),
            'discount' => number_format((float) $this->discount, 2),
            ...$taxes,
            'total' => number_format((float) $this->total, 2),
            'bucket' => $this->bucket,
            'folio' => $this->folio,
            'room' => $this->room,
            'code' => $this->code,
            'status' => $this->status->label,
            'staff' => $this->staff ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'charge' => $this->charge,
            'booking' => $this->booking,
            'guest' => $this->guest,
            'base' => round((float) $this->base, 2),
            'discount' => round((float) $this->discount, 2),
            ...array_combine(
                array_map(fn (string $code): string => "tax:{$code}", array_keys($this->taxes)),
                array_map(fn (string $amount): float => round((float) $amount, 2), array_values($this->taxes)),
            ),
            'total' => round((float) $this->total, 2),
            'bucket' => $this->bucket,
            'folio' => $this->folio,
            'room' => $this->room,
            'code' => $this->code,
            'status' => $this->status->label,
            'staff' => $this->staff ?? '',
        ];
    }
}
