<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioData;
use App\Data\Folio\FolioOptionData;
use App\Data\Folio\FolioRailItemData;
use App\Data\Folio\FolioRowData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Data\Shared\BadgeData;
use App\Enums\FolioRowTag;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
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
     * `$can` says what the user may do: post a charge, reverse a line, transfer a line, apply or remove a discount, close or reopen the folio, record or refund a payment. Rows and options carry only
     * what is allowed now, so the page never works it out.
     *
     * @param  list<ReservationUnitOptionData>  $units
     * @param  array{postCharge?: bool, reverse?: bool, transfer?: bool, discount?: bool, close?: bool, reopen?: bool, recordPayment?: bool, refund?: bool, printVoucher?: bool}  $can
     * @return array{folios: list<FolioRailItemData>, folio: FolioData|null}
     */
    public function build(Property $property, Reservation $reservation, array $units, string $selected = '', array $can = []): array
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
            'folio' => $open === null ? null : $this->data($open, $entries, $units, $blocks, $balances, $can),
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
     * The folio's name with its payer, as the transfer and routing selects show it: "Third party · Blue Reef Dive".
     * Expects `payerGuest` to be loaded.
     *
     * @param  array<int, string>  $labels  Unit label by unit id
     */
    public function optionLabel(Folio $folio, array $labels): string
    {
        return trim($this->name($folio, $labels).' · '.$folio->payerGuest?->full_name, ' ·');
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
     * @param  Collection<int, array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}>  $entries
     * @param  list<ReservationUnitOptionData>  $units
     * @param  list<array{target: int|null, unit: int, date: string, rows: list<FolioRowData>}>  $blocks
     * @param  array<int, string>  $balances
     * @param  array{postCharge?: bool, reverse?: bool, transfer?: bool, discount?: bool, close?: bool, reopen?: bool, recordPayment?: bool, refund?: bool}  $can
     */
    private function data(array $entry, Collection $entries, array $units, array $blocks, array $balances, array $can): FolioData
    {
        $folio = $entry['folio'];
        $isOpen = $folio?->status === FolioStatus::Open;
        $targets = $isOpen && ($can['transfer'] ?? false) ? $entries
            ->filter(fn (array $other): bool => $other['folio'] !== null && $other['folio']->id !== $folio->id && $other['folio']->status === FolioStatus::Open)
            ->map(fn (array $other): FolioOptionData => new FolioOptionData($other['folio']->id, trim($other['item']->name.' · '.$other['item']->subtitle, ' ·')))
            ->values()
            ->all() : [];
        $postUnits = $isOpen && ($can['postCharge'] ?? false) ? collect($units)
            ->filter(fn (ReservationUnitOptionData $unit): bool => in_array($unit->status->value, [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value], true))
            ->filter(fn (ReservationUnitOptionData $unit): bool => $folio->reservation_unit_id === null || $unit->id === $folio->reservation_unit_id)
            ->map(fn (ReservationUnitOptionData $unit): FolioOptionData => new FolioOptionData($unit->id, $unit->label))
            ->values()
            ->all() : [];
        $allowed = $isOpen ? array_values(array_filter([
            ($can['reverse'] ?? false) ? 'reverse' : null,
            ($can['transfer'] ?? false) && $targets !== [] ? 'transfer' : null,
        ])) : [];
        $scheduled = collect($blocks)
            ->filter(fn (array $block): bool => $folio === null ? ($block['target'] === null && $block['unit'] === $entry['unit']) : $block['target'] === $folio->id)
            ->flatMap(fn (array $block): array => $block['rows'])
            ->values();
        if ($can['printVoucher'] ?? false) {
            $scheduled->each(fn (FolioRowData $row) => $row->printable = true);
        }

        [$posted, $discountable] = $folio === null ? [collect(), false] : $this->postedRows($folio, $allowed, $isOpen && ($can['discount'] ?? false), $isOpen && ($can['refund'] ?? false), $can['recordPayment'] ?? false, $can['printVoucher'] ?? false);
        $balance = $folio === null ? null : ($balances[$folio->id] ?? '0.00');
        $projected = $scheduled->reduce(fn (BigDecimal $sum, FolioRowData $row): BigDecimal => $sum->plus($row->amount), BigDecimal::of($balance ?? '0'));

        return new FolioData(
            key: $entry['key'],
            kind: $entry['item']->kind,
            name: $entry['item']->name,
            status: $entry['item']->status,
            number: $folio?->number,
            invoiceNumber: $folio?->invoice_number,
            balance: $balance,
            projectedTotal: (string) $projected->toScale(2),
            lineCount: $posted->count(),
            rows: [...$posted->all(), ...$scheduled->all()],
            canClose: $isOpen && ($can['close'] ?? false),
            canReopen: $folio?->status === FolioStatus::Closed && ($can['reopen'] ?? false),
            canApplyDiscount: $isOpen && ($can['discount'] ?? false) && $discountable,
            canPostCharge: $postUnits !== [],
            postUnits: $postUnits,
            transferTargets: $targets,
            canRecordPayment: ($can['recordPayment'] ?? false) && ($folio === null || $isOpen),
            canPrintVoucher: $posted->contains(fn (FolioRowData $row): bool => $row->printable) || $scheduled->contains(fn (FolioRowData $row): bool => $row->printable),
            paymentUnitId: $folio === null ? $entry['unit'] : null,
        );
    }

    /**
     * The folio's lines in ledger order: each charge or payment, then its children (a discount, then its tax).
     *
     * A charge that is not a child, a reversal or already reversed takes the allowed actions. A discount line that
     * is not reversed offers Remove discount. A payment row carries its reference and the amount left to refund, and
     * offers Refund when something is left, and a receipt to a user with `payment.record`. A refund row is tagged Refund. The second value says whether any charge
     * can still take a discount (a live charge with no live discount line).
     *
     * @param  list<string>  $allowed
     * @return array{0: Collection<int, FolioRowData>, 1: bool}
     */
    private function postedRows(Folio $folio, array $allowed, bool $canRemoveDiscount, bool $canRefund, bool $canDownloadReceipt, bool $canPrintVoucher): array
    {
        $lines = $folio->lines()->orderBy('posting_date')->orderBy('id')->get();
        $payments = $folio->payments()->withSum('refunds', 'amount')->get()->keyBy('folio_line_id');
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $children = $lines->whereNotNull('parent_line_id')->groupBy('parent_line_id');
        $rows = collect();

        $walk = function (FolioLine $line, int $level) use (&$walk, $children, $reversed, $rows, $allowed, $canRemoveDiscount, $payments, $canRefund, $canDownloadReceipt, $canPrintVoucher): void {
            $tags = [];

            if ($line->reversal_of_id !== null) {
                $tags[] = FolioRowTag::Reversal;
            }

            if ($reversed->has($line->id)) {
                $tags[] = FolioRowTag::Reversed;
            }

            $live = $tags === [];
            $actions = match (true) {
                $level === 0 && $live && $line->kind === TransactionKind::Charge => $allowed,
                $level === 1 && $live && $line->kind === TransactionKind::Adjustment && $canRemoveDiscount => ['remove_discount'],
                default => [],
            };
            $payment = $payments->get($line->id);
            $refundable = null;

            if ($payment !== null && $payment->refund_of_id !== null) {
                $tags[] = FolioRowTag::Refund;
            } elseif ($payment !== null) {
                $refundable = (string) BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'))->toScale(2);
                $actions = $canRefund && $payment->status === PaymentStatus::Completed && BigDecimal::of($refundable)->isPositive() ? ['refund'] : [];
            }

            $rows->push(new FolioRowData('line:'.$line->id, 'posted', $line->posting_date->toDateString(), $line->description, min($level, 2), null, (string) $line->amount, array_map(BadgeData::fromFolioRowTag(...), $tags), $line->id, $actions, $payment?->reference, $payment?->id, $refundable, $canDownloadReceipt && $payment !== null && $payment->refund_of_id === null, $line->kind, $canPrintVoucher && FolioVoucher::isPrintable($line, $reversed->has($line->id))));

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

        $discounted = $lines
            ->filter(fn (FolioLine $line): bool => $line->kind === TransactionKind::Adjustment && $line->parent_line_id !== null && $line->reversal_of_id === null && ! $reversed->has($line->id))
            ->pluck('parent_line_id')
            ->flip();
        $discountable = $lines->contains(fn (FolioLine $line): bool => $line->kind === TransactionKind::Charge
            && $line->parent_line_id === null
            && $line->reversal_of_id === null
            && ! $reversed->has($line->id)
            && ! $discounted->has($line->id));

        return [$rows, $discountable];
    }
}
