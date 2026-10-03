<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioData;
use App\Data\Folio\FolioRailItemData;
use App\Data\Folio\FolioRowData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Data\Shared\BadgeData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Reads the folio tab of a reservation: the rail (every folio that is not Voided, then a read-only entry for each
 * unit whose Scheduled rows have no folio yet) and the open entry with its Posted and Scheduled rows. Balance is the
 * posted lines only. Projected total adds the Scheduled rows. It writes nothing and creates no folio.
 * See docs/13-folio/05-folio-page.md.
 */
class FolioStatement
{
    public function __construct(private ScheduledFolioRows $scheduledRows) {}

    /**
     * Expects the property's `settings` and the reservation's `booker` to be loaded. An unknown `$selected` falls back to the Master folio, or
     * the first entry.
     *
     * @param  list<ReservationUnitOptionData>  $units
     * @return array{folios: list<FolioRailItemData>, folio: FolioData|null}
     */
    public function build(Property $property, Reservation $reservation, array $units, string $selected = ''): array
    {
        $labels = collect($units)->mapWithKeys(fn (ReservationUnitOptionData $unit): array => [$unit->id => $unit->assigned ?? $unit->roomType])->all();
        $folios = $this->folios($reservation, array_keys($labels));
        $balances = $this->balances($folios);
        $blocks = $this->scheduledRows->forReservation($property, $reservation, $labels);

        $entries = $folios->map(fn (Folio $folio): array => [
            'key' => (string) $folio->id,
            'folio' => $folio,
            'unit' => null,
            'item' => new FolioRailItemData(
                (string) $folio->id,
                $folio->type->value,
                $this->name($folio, $labels),
                $folio->payerGuest?->full_name,
                BadgeData::fromFolioStatus($folio->status),
                $balances[$folio->id] ?? '0.00',
            ),
        ])->values();

        $notStarted = collect($blocks)->where('target', null)->pluck('unit')->unique()->values();

        foreach ($notStarted as $unitId) {
            $entries->push([
                'key' => 'u'.$unitId,
                'folio' => null,
                'unit' => $unitId,
                'item' => new FolioRailItemData('u'.$unitId, 'not_started', __('Unit :unit', ['unit' => $labels[$unitId] ?? '—']), __('Not started'), null, null),
            ]);
        }

        $open = $entries->firstWhere('key', $selected)
            ?? $entries->first(fn (array $entry): bool => $entry['folio']?->type === FolioType::Master)
            ?? $entries->first();

        return [
            'folios' => $entries->map(fn (array $entry): FolioRailItemData => $entry['item'])->all(),
            'folio' => $open === null ? null : $this->data($open, $blocks, $balances),
        ];
    }

    /**
     * Master first, then Unit folios in unit order, then ThirdParty folios.
     *
     * @param  list<int>  $unitOrder
     * @return Collection<int, Folio>
     */
    private function folios(Reservation $reservation, array $unitOrder): Collection
    {
        return Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->with('payerGuest')
            ->get()
            ->sortBy(fn (Folio $folio): array => [
                match ($folio->type) {
                    FolioType::Master => 0,
                    FolioType::Unit => 1,
                    FolioType::ThirdParty => 2,
                },
                $folio->type === FolioType::Unit ? (int) array_search($folio->reservation_unit_id, $unitOrder, true) : 0,
                $folio->id,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Folio>  $folios
     * @return array<int, string> Balance by folio id
     */
    private function balances(Collection $folios): array
    {
        return FolioLine::query()
            ->whereIn('folio_id', $folios->modelKeys())
            ->groupBy('folio_id')
            ->selectRaw('folio_id, SUM(amount)::numeric(14, 2) AS balance')
            ->pluck('balance', 'folio_id')
            ->map(fn (mixed $balance): string => (string) $balance)
            ->all();
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function name(Folio $folio, array $labels): string
    {
        return match ($folio->type) {
            FolioType::Master => __('Master'),
            FolioType::Unit => __('Unit :unit', ['unit' => $labels[$folio->reservation_unit_id] ?? '—']),
            FolioType::ThirdParty => __('Third party'),
        };
    }

    /**
     * @param  array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}  $entry
     * @param  list<array{target: int|null, unit: int, date: string, rows: list<FolioRowData>}>  $blocks
     * @param  array<int, string>  $balances
     */
    private function data(array $entry, array $blocks, array $balances): FolioData
    {
        $folio = $entry['folio'];
        $scheduled = collect($blocks)
            ->filter(fn (array $block): bool => $folio === null ? ($block['target'] === null && $block['unit'] === $entry['unit']) : $block['target'] === $folio->id)
            ->flatMap(fn (array $block): array => $block['rows'])
            ->values();
        $posted = $folio === null ? collect() : $this->postedRows($folio);
        $balance = $folio === null ? null : ($balances[$folio->id] ?? '0.00');
        $projected = $scheduled->reduce(fn (BigDecimal $sum, FolioRowData $row): BigDecimal => $sum->plus($row->amount), BigDecimal::of($balance ?? '0'));

        return new FolioData(
            key: $entry['key'],
            kind: $entry['item']->kind,
            name: $entry['item']->name,
            status: $entry['item']->status,
            invoiceNumber: $folio?->invoice_number,
            balance: $balance,
            projectedTotal: (string) $projected->toScale(2),
            lineCount: $posted->count(),
            rows: [...$posted->all(), ...$scheduled->all()],
        );
    }

    /**
     * The folio's lines in ledger order: each charge or payment, then its children (a discount, then its tax).
     *
     * @return Collection<int, FolioRowData>
     */
    private function postedRows(Folio $folio): Collection
    {
        $lines = $folio->lines()->orderBy('posting_date')->orderBy('id')->get();
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $children = $lines->whereNotNull('parent_line_id')->groupBy('parent_line_id');
        $rows = collect();

        $walk = function (FolioLine $line, int $level) use (&$walk, $children, $reversed, $rows): void {
            $tags = [];

            if ($line->reversal_of_id !== null) {
                $tags[] = 'Reversal';
            }

            if ($reversed->has($line->id)) {
                $tags[] = 'Reversed';
            }

            $rows->push(new FolioRowData('line:'.$line->id, 'posted', $line->posting_date->toDateString(), $line->description, min($level, 2), null, (string) $line->amount, $tags));

            foreach ($children->get($line->id, []) as $child) {
                $walk($child, $level + 1);
            }
        };

        $ids = $lines->pluck('id')->flip();

        foreach ($lines as $line) {
            if ($line->parent_line_id === null || ! $ids->has($line->parent_line_id)) {
                $walk($line, 0);
            }
        }

        return $rows;
    }
}
