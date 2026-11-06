<?php

namespace App\Services\Reservations;

use App\Data\Reservations\AgentBillData;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;

/**
 * The whole booking split by who pays, following the folios: posted lines by the folio they sit on, Scheduled rows by the
 * folio the router will choose. A folio a company pays is the agent's part. Anything else, also a unit with no folio
 * yet, is the guest's part. A reversed line and its reversal are left out, so a corrected line shows once. Payments
 * are not charges and are left out. It writes nothing.
 */
class AgentBill
{
    public function __construct(private ScheduledFolioRows $scheduledFolioRows, private UnitNationality $unitNationality) {}

    public function for(Reservation $reservation): AgentBillData
    {
        $reservation->loadMissing(['booker', 'property.settings']);

        $folios = Folio::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', '<>', FolioStatus::Voided->value)
            ->get(['id', 'payer_company_id'])
            ->keyBy('id');
        $parts = ['agent' => [], 'guest' => []];

        $lines = FolioLine::query()
            ->whereIn('folio_id', $folios->keys()->all())
            ->where('kind', '<>', TransactionKind::Payment->value)
            ->with('transactionCode')
            ->orderBy('id')
            ->get();
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();

        foreach ($lines as $line) {
            if ($line->reversal_of_id !== null || $reversed->has($line->id)) {
                continue;
            }

            $part = $folios->get($line->folio_id)?->payer_company_id === null ? 'guest' : 'agent';
            $this->add($parts[$part], $line->kind, $this->label($line->description, $line->kind, $line->transactionCode), $line->quantity, (string) $line->amount);
        }

        foreach ($this->scheduledFolioRows->forReservation($reservation->property, $reservation, []) as $block) {
            $part = $block['target'] !== null && $folios->get($block['target'])?->payer_company_id !== null ? 'agent' : 'guest';

            foreach ($block['rows'] as $row) {
                $kind = $row->kind ?? TransactionKind::Charge;
                $quantity = preg_match('/ ×(\d+)$/u', $row->description, $match) === 1 ? (int) $match[1] : 1;
                $this->add($parts[$part], $kind, $this->label($row->description, $kind, $block['code']), $quantity, $row->amount);
            }
        }

        $agent = $this->finish($parts['agent']);
        $guest = $this->finish($parts['guest']);

        return new AgentBillData(
            agentLines: $agent['lines'],
            agentTotal: $agent['total'],
            guestLines: $guest['lines'],
            guestTotal: $guest['total'],
            total: (string) BigDecimal::of($agent['total'])->plus($guest['total'])->toScale(2),
            nationalityMissing: $this->nationalityMissing($reservation),
        );
    }

    /**
     * Whether a room still to arrive has no nationality while a tax depends on it: that tax cannot show until it is set.
     */
    private function nationalityMissing(Reservation $reservation): bool
    {
        $hasForeignTax = Tax::query()->where('property_id', $reservation->property_id)->where('enabled', true)->where('foreign_guests_only', true)->exists();

        if (! $hasForeignTax) {
            return false;
        }

        $units = $reservation->units()->where('status', UnitStatus::Confirmed->value)->get();

        return in_array(null, $this->unitNationality->forUnits($units), true);
    }

    /**
     * Room nights read as their transaction code, so 3 nights make one line. Anything else keeps its own name, such as
     * "Jetty Fee · Adult", without the quantity.
     */
    private function label(string $description, TransactionKind $kind, TransactionCode $code): string
    {
        if ($kind === TransactionKind::Charge && in_array($code->system_key, [SystemTransactionCode::RoomRevenue->value, SystemTransactionCode::DayUseRevenue->value], true)) {
            return $code->name;
        }

        return (string) preg_replace('/ ×\d+$/u', '', $description);
    }

    /**
     * @param  array<string, array{rank: int, label: string, quantity: int, amount: BigDecimal}>  $part
     */
    private function add(array &$part, TransactionKind $kind, string $label, int $quantity, string $amount): void
    {
        $part[$label] ??= ['rank' => match ($kind) {
            TransactionKind::Charge => 0,
            TransactionKind::Tax => 2,
            default => 1,
        }, 'label' => $label, 'quantity' => 0, 'amount' => BigDecimal::zero()];
        $part[$label]['quantity'] += $quantity;
        $part[$label]['amount'] = $part[$label]['amount']->plus($amount);
    }

    /**
     * Charges first, then adjustments, then tax. A line that adds up to nothing is left out.
     *
     * @param  array<string, array{rank: int, label: string, quantity: int, amount: BigDecimal}>  $part
     * @return array{lines: list<array{label: string, quantity: int, amount: string}>, total: string}
     */
    private function finish(array $part): array
    {
        $rows = array_values(array_filter($part, fn (array $row): bool => ! $row['amount']->isZero()));
        $order = array_flip(array_column($rows, 'label'));
        usort($rows, fn (array $first, array $second): int => [$first['rank'], $order[$first['label']]] <=> [$second['rank'], $order[$second['label']]]);
        $total = array_reduce($rows, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['amount']), BigDecimal::zero());

        return [
            'lines' => array_map(fn (array $row): array => ['label' => $row['label'], 'quantity' => $row['quantity'], 'amount' => (string) $row['amount']->toScale(2)], $rows),
            'total' => (string) $total->toScale(2),
        ];
    }
}
