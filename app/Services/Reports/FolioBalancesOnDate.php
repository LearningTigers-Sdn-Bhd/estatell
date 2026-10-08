<?php

namespace App\Services\Reports;

use App\Enums\FolioStatus;
use App\Enums\PaymentPurpose;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The balance of every folio of a property on one date, from the immutable ledger: the sum of the lines with a
 * posting date on or before the date, so a past date is exact. One grouped query over the folio lines, joined to
 * `payments` through `folio_line_id` to tell a refund (a payment row with `refund_of_id`) from a payment and to find
 * the security deposits. Voided folios are left out. A folio with no line by the date has no balance and is left out.
 * See docs/22-reports/01-core-reports.md.
 */
class FolioBalancesOnDate
{
    /**
     * @param  bool|null  $debit  True keeps the folios with a balance above 0, false the ones below 0, null every folio.
     * @param  list<int>|null  $folioIds  Only these folios, when given.
     * @return array<int, FolioBalanceOnDate> By folio id
     */
    public function forDate(Property $property, string $date, ?bool $debit = null, ?array $folioIds = null): array
    {
        $charge = [TransactionKind::Charge->value, TransactionKind::Tax->value, TransactionKind::Adjustment->value];
        $payment = TransactionKind::Payment->value;

        $query = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->leftJoin('payments', 'payments.folio_line_id', '=', 'folio_lines.id')
            ->where('folios.property_id', $property->id)
            ->where('folios.status', '!=', FolioStatus::Voided->value)
            ->where('folio_lines.posting_date', '<=', $date)
            ->when($folioIds !== null, fn ($query) => $query->whereIn('folios.id', $folioIds))
            ->groupBy('folios.id')
            ->selectRaw(
                'folios.id AS folio_id,'
                .' COALESCE(SUM(CASE WHEN folio_lines.kind IN (?, ?, ?) THEN folio_lines.amount ELSE 0 END), 0)::numeric(14, 2) AS charges,'
                .' COALESCE(-SUM(CASE WHEN folio_lines.kind = ? AND payments.refund_of_id IS NULL THEN folio_lines.amount ELSE 0 END), 0)::numeric(14, 2) AS received,'
                .' COALESCE(SUM(CASE WHEN payments.refund_of_id IS NOT NULL THEN folio_lines.amount ELSE 0 END), 0)::numeric(14, 2) AS refunded,'
                .' COALESCE(-SUM(CASE WHEN payments.purpose = ? THEN folio_lines.amount ELSE 0 END), 0)::numeric(14, 2) AS security_deposit,'
                .' COALESCE(SUM(folio_lines.amount), 0)::numeric(14, 2) AS balance',
                [...$charge, $payment, PaymentPurpose::SecurityDeposit->value],
            );

        if ($debit !== null) {
            $query->havingRaw($debit ? 'SUM(folio_lines.amount) > 0' : 'SUM(folio_lines.amount) < 0');
        }

        $balances = [];

        foreach ($query->get() as $row) {
            $folioId = (int) $row->getAttribute('folio_id');
            $balances[$folioId] = new FolioBalanceOnDate(
                folioId: $folioId,
                charges: $this->money($row->getAttribute('charges')),
                received: $this->money($row->getAttribute('received')),
                refunded: $this->money($row->getAttribute('refunded')),
                securityDeposit: $this->money($row->getAttribute('security_deposit')),
                balance: $this->money($row->getAttribute('balance')),
            );
        }

        return $balances;
    }

    private function money(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }
}
