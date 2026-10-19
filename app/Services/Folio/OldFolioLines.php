<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostOpeningBalance;
use App\Data\Folio\OldFolioRowData;
use App\Enums\OldFolioRowKind;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ReservationUnit;
use App\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Reads the lines that the old folio page saved. A saved line has the key `migrated:{folio_id}:{row key}`, and a line
 * that replaced a changed one adds `~{n}` (`migrated:{folio_id}:{row key}~1`), so the row key stays the same for the
 * life of a row. A line is live when it is a top-level migrated line that has no reversal and, for a payment, no refund.
 * It gives each live line back as the row the page shows, so the page can edit it. It writes nothing.
 * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
class OldFolioLines
{
    private const KEY_PATTERN = '/^migrated:(\d+):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:~(\d+))?$/i';

    /**
     * The row key of a saved line key, or null when the key is not an old folio line key.
     */
    public static function rowKey(string $idempotencyKey): ?string
    {
        return preg_match(self::KEY_PATTERN, $idempotencyKey, $found) === 1 ? strtolower($found[2]) : null;
    }

    /**
     * The key of a line: `migrated:{folio_id}:{row key}`, with `~{version}` from the second line of a row on.
     */
    public static function lineKey(int $folioId, string $rowKey, int $version = 0): string
    {
        return 'migrated:'.$folioId.':'.$rowKey.($version > 0 ? '~'.$version : '');
    }

    /**
     * The live saved lines of some folios, by row key, each with the row it shows as. A line the user moved to another
     * folio with Move charges has another key and is not listed: the page does not edit it.
     *
     * @param  array<int, int|string>  $folioIds
     * @return array<string, array{line: FolioLine, payment: Payment|null, row: OldFolioRowData}>
     */
    public function live(int $propertyId, array $folioIds): array
    {
        if ($folioIds === []) {
            return [];
        }

        $lines = FolioLine::query()
            ->whereIn('folio_id', $folioIds)
            ->where('migrated', true)
            ->where('idempotency_key', 'like', 'migrated:%')
            ->whereNull('reversal_of_id')
            ->whereNull('parent_line_id')
            ->whereDoesntHave('reversal')
            ->with(['payment' => fn ($payments) => $payments->withCount('refunds')])
            ->orderBy('service_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (FolioLine $line): bool => $line->payment === null || $line->payment->refunds_count === 0)
            ->values();
        $codes = $lines->pluck('transaction_code_id')->unique()->all();
        $items = ChargeItem::query()->where('property_id', $propertyId)->whereIn('transaction_code_id', $codes)->orderBy('id')->get()->groupBy('transaction_code_id')->all();
        $taxes = Tax::query()->where('property_id', $propertyId)->whereIn('transaction_code_id', $codes)->orderBy('id')->get()->keyBy('transaction_code_id')->all();
        $unitMorph = (new ReservationUnit)->getMorphClass();
        $live = [];

        foreach ($lines as $line) {
            $key = self::rowKey($line->idempotency_key);

            if ($key === null) {
                continue;
            }

            $live[$key] = ['line' => $line, 'payment' => $line->payment, 'row' => $this->row($line, $key, $unitMorph, $items, $taxes)];
        }

        return $live;
    }

    /**
     * How many lines each row key has had in some folios, live or reversed. The next line of a row takes this count as
     * its version, so its key is new.
     *
     * @param  array<int, int|string>  $folioIds
     * @return array<string, int>
     */
    public function versions(array $folioIds): array
    {
        if ($folioIds === []) {
            return [];
        }

        $counts = [];

        foreach (FolioLine::query()->whereIn('folio_id', $folioIds)->where('idempotency_key', 'like', 'migrated:%')->pluck('idempotency_key') as $key) {
            $rowKey = self::rowKey($key);

            if ($rowKey !== null) {
                $counts[$rowKey] = ($counts[$rowKey] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The live opening balance amount of each folio, by folio id (folios without one are left out).
     *
     * @param  array<int, int|string>  $folioIds
     * @return array<int, string>
     */
    public function openingBalances(int $propertyId, array $folioIds): array
    {
        $code = PostOpeningBalance::code($propertyId);

        if ($code === null || $folioIds === []) {
            return [];
        }

        return FolioLine::query()
            ->whereIn('folio_id', $folioIds)
            ->where('transaction_code_id', $code->id)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->orderBy('id')
            ->get(['folio_id', 'amount'])
            ->mapWithKeys(fn (FolioLine $line): array => [$line->folio_id => (string) $line->amount])
            ->all();
    }

    /**
     * @param  array<array-key, EloquentCollection<int, ChargeItem>>  $items  Charge items by transaction code id
     * @param  array<array-key, Tax>  $taxes  Taxes by transaction code id
     */
    private function row(FolioLine $line, string $key, string $unitMorph, array $items, array $taxes): OldFolioRowData
    {
        $common = ['key' => $key, 'serviceDate' => $line->service_date->toDateString(), 'folioId' => $line->folio_id, 'lineId' => $line->id];

        if ($line->kind === TransactionKind::Payment) {
            return new OldFolioRowData(...$common, kind: OldFolioRowKind::Payment, paymentMethodId: $line->payment?->payment_method_id, description: $line->payment?->reference, amount: (string) BigDecimal::of($line->amount)->abs()->toScale(2));
        }

        if ($line->kind === TransactionKind::Tax) {
            return new OldFolioRowData(...$common, kind: OldFolioRowKind::Tax, taxId: ($taxes[$line->transaction_code_id] ?? null)?->id, description: $line->description, amount: (string) $line->amount);
        }

        if ($line->source_type === $unitMorph) {
            return new OldFolioRowData(...$common, kind: OldFolioRowKind::Room, unitId: $line->source_id, quantity: $line->quantity, description: $line->description, amount: (string) $line->unit_amount);
        }

        $candidates = $items[$line->transaction_code_id] ?? new EloquentCollection;
        $item = $candidates->first(fn (ChargeItem $candidate): bool => $candidate->name === $line->description) ?? $candidates->first();

        return new OldFolioRowData(...$common, kind: OldFolioRowKind::Charge, chargeItemId: $item?->id, quantity: $line->quantity, description: $line->description, amount: (string) $line->unit_amount);
    }
}
