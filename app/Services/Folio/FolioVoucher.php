<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioRowData;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Guests\CountryOptions;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Reads folio lines and Scheduled rows and gathers what a voucher shows. Any posted line that is not a payment, a
 * reversal or already reversed can be printed, children (discount, tax) on their own as well, and so can any Scheduled
 * row, because a voucher writes nothing. Posted amounts come straight from the ledger and Scheduled amounts from
 * `ScheduledFolioRows`. A voucher does not mark which rows were Scheduled. See docs/13-folio/05-folio-page.md#print-voucher.
 */
class FolioVoucher
{
    /** The most rows one voucher can hold, posted and Scheduled together. */
    public const MAX_LINES = 100;

    public function __construct(
        private CountryOptions $countryOptions,
        private ScheduledFolioRows $scheduledRows,
    ) {}

    /**
     * A line can be printed when it is not a payment, not a reversal and not reversed.
     */
    public static function isPrintable(FolioLine $line, bool $isReversed): bool
    {
        return $line->kind !== TransactionKind::Payment && $line->reversal_of_id === null && ! $isReversed;
    }

    /**
     * The folio's lines that can be printed, in ledger order.
     *
     * @return Collection<int, FolioLine>
     */
    public function printableLines(Folio $folio): Collection
    {
        $lines = $folio->lines()->orderBy('posting_date')->orderBy('id')->get();
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();

        return $lines->filter(fn (FolioLine $line): bool => self::isPrintable($line, $reversed->has($line->id)))->values();
    }

    /**
     * The Scheduled rows a voucher can take for a folio, or for a unit that has no folio yet (the rows no folio
     * holds). It is the same choice the folio tab makes, so the Dialog and the PDF agree.
     *
     * @return Collection<int, FolioRowData>
     */
    public function scheduledRows(Property $property, Reservation $reservation, ?Folio $folio, ?ReservationUnit $unit = null): Collection
    {
        $reservation->loadMissing('booker');
        $property->loadMissing('settings');

        return collect($this->scheduledRows->forReservation($property, $reservation, []))
            ->filter(fn (array $block): bool => $folio !== null ? $block['target'] === $folio->id : $block['target'] === null && $block['unit'] === $unit?->id)
            ->flatMap(fn (array $block): array => $block['rows'])
            ->values();
    }

    /**
     * @param  list<int>  $lineIds  ids of lines of this folio, each printable (see `printableLines()`)
     * @param  Collection<int, FolioRowData>|null  $scheduled  the chosen Scheduled rows of this folio
     */
    public function forLines(Folio $folio, array $lineIds, ?Collection $scheduled = null): FolioVoucherDocument
    {
        $folio->loadMissing(['property.settings', 'reservation.booker', 'payerGuest', 'payerCompany']);
        $parents = $folio->lines()->pluck('parent_line_id', 'id');
        $chosen = $this->printableLines($folio)->whereIn('id', $lineIds)->values();
        $posted = $chosen->map(fn (FolioLine $line): array => [
            'date' => $line->posting_date->format('j M Y'),
            'description' => $line->description,
            'level' => $this->level($line->id, $parents, $chosen->pluck('id')),
            'amount' => (string) BigDecimal::of($line->amount)->toScale(2),
        ]);

        return $this->document($folio->property, $folio->reservation, $folio, $folio->payerGuest ?? $folio->reservation->booker, $folio->reservation_unit_id, $posted, $scheduled ?? collect());
    }

    /**
     * A voucher of Scheduled rows for a unit that has no folio yet.
     *
     * @param  Collection<int, FolioRowData>  $scheduled  the chosen Scheduled rows of this unit
     */
    public function forUnit(Property $property, ReservationUnit $unit, Collection $scheduled): FolioVoucherDocument
    {
        $property->loadMissing('settings');
        $unit->loadMissing('reservation.booker');

        return $this->document($property, $unit->reservation, null, $unit->reservation->booker, $unit->id, collect(), $scheduled);
    }

    /**
     * @param  Collection<int, array{date: string, description: string, level: int, amount: string}>  $posted
     * @param  Collection<int, FolioRowData>  $scheduled
     */
    private function document(Property $property, Reservation $reservation, ?Folio $folio, mixed $guest, ?int $unitId, Collection $posted, Collection $scheduled): FolioVoucherDocument
    {
        $timeZone = $property->settings->time_zone ?? config('app.timezone');
        $later = $scheduled->map(fn (FolioRowData $row): array => [
            'date' => date('j M Y', strtotime($row->date)),
            'description' => $row->description,
            'level' => $this->scheduledLevel($row, $scheduled),
            'amount' => (string) BigDecimal::of($row->amount)->toScale(2),
        ]);
        $sum = fn (Collection $rows): BigDecimal => $rows->reduce(fn (BigDecimal $total, array $row): BigDecimal => $total->plus($row['amount']), BigDecimal::zero());

        return new FolioVoucherDocument(
            issuedOn: now()->setTimezone($timeZone)->format('j M Y'),
            reservationReference: $reservation->reference,
            folioNumber: $folio?->number,
            guestName: $guest->full_name,
            nationality: $this->countryOptions->name($guest->nationality_code),
            stay: $this->stay($reservation, $unitId),
            currency: $property->settings?->currency->value ?? '',
            rows: [...$posted->all(), ...$later->all()],
            total: (string) $sum($posted->concat($later))->toScale(2),
            billedTo: $folio?->payerCompany?->name,
        );
    }

    /**
     * How many of the line's ancestors are on the voucher too: 0 for a line with none (a tax line printed on its own
     * stands at the top), 1 for a child, 2 for a grandchild.
     *
     * @param  Collection<int, int|null>  $parents  parent line id by line id
     * @param  Collection<int, int>  $chosen  ids of the lines on the voucher
     */
    private function level(int $lineId, Collection $parents, Collection $chosen): int
    {
        $level = 0;
        $parent = $parents->get($lineId);

        while ($parent !== null) {
            if ($chosen->contains($parent)) {
                $level++;
            }

            $parent = $parents->get($parent);
        }

        return min($level, 2);
    }

    /**
     * A Scheduled row's level when its parent is on the voucher too. A child key starts with its parent's key and a
     * colon (`night:5:tax:1` under `night:5`), so a child whose parent is not chosen stands at the top.
     *
     * @param  Collection<int, FolioRowData>  $rows
     */
    private function scheduledLevel(FolioRowData $row, Collection $rows): int
    {
        return $rows->contains(fn (FolioRowData $other): bool => $other->level < $row->level && str_starts_with($row->key, $other->key.':')) ? $row->level : 0;
    }

    private function stay(Reservation $reservation, ?int $unitId): string
    {
        $units = $reservation->units();

        if ($unitId !== null) {
            $units->whereKey($unitId);
        }

        $arrival = $units->min('arrival_date');
        $departure = $units->max('departure_date');

        return $arrival === null ? '' : date('j M Y', strtotime((string) $arrival)).' → '.date('j M Y', strtotime((string) $departure));
    }
}
