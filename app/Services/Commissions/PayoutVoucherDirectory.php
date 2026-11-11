<?php

namespace App\Services\Commissions;

use App\Data\Commissions\PayoutVoucherData;
use App\Data\Commissions\PayoutVoucherLineData;
use App\Data\Commissions\PayoutVoucherRowData;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Reads payout vouchers for the admin pages: the filtered list and one voucher with its lines.
 * See docs/30-commission-fees/05-platform-pages.md.
 */
class PayoutVoucherDirectory
{
    public const int PER_PAGE = 25;

    public function __construct(private PayoutVoucherTotals $totals) {}

    /**
     * @return LengthAwarePaginator<int, PayoutVoucher>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return QueryBuilder::for(PayoutVoucher::class, $request)
            ->allowedFilters(
                AllowedFilter::exact('cycle', 'period_end'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('property', 'property_id'),
            )
            ->allowedSorts('period_end', 'amount')
            ->defaultSort('-period_end')
            ->with(PayoutVoucherRowData::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Every cycle that has a voucher, newest first, as `16 Sep 2026 – 15 Oct 2026`.
     *
     * @return list<array{value: string, label: string}>
     */
    public function cycleOptions(): array
    {
        return array_values(PayoutVoucher::query()
            ->select('period_end')
            ->distinct()
            ->orderByDesc('period_end')
            ->pluck('period_end')
            ->map(function (CarbonImmutable $end): array {
                $start = $end->subMonthNoOverflow()->addDay();

                return ['value' => $end->toDateString(), 'label' => $start->format('j M Y').' – '.$end->format('j M Y')];
            })
            ->all());
    }

    /**
     * The properties that have a voucher, by name.
     *
     * @return list<array{value: string, label: string}>
     */
    public function propertyOptions(): array
    {
        return array_values(Property::query()
            ->whereIn('id', PayoutVoucher::query()->select('property_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Property $property): array => ['value' => (string) $property->id, 'label' => $property->name])
            ->all());
    }

    /**
     * The voucher page. The bank account is the one saved on approval, or the property's first active account before.
     */
    public function detail(PayoutVoucher $voucher): PayoutVoucherData
    {
        $voucher->loadMissing([...PayoutVoucherRowData::RELATIONS, 'approver:id,name']);

        $lines = $voucher->entries()
            ->with('reservation:id,reference')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (CommissionEntry $entry): PayoutVoucherLineData => new PayoutVoucherLineData(
                id: $entry->id,
                dueDate: $entry->due_date?->toDateString(),
                reservationId: $entry->reservation_id,
                bookingReference: $entry->reservation?->reference,
                label: $this->totals->label($this->totals->key($entry)),
                reason: $entry->reason,
                amount: $entry->amount,
            ));

        return new PayoutVoucherData(
            voucher: PayoutVoucherRowData::fromModel($voucher),
            lines: array_values($lines->all()),
            totals: array_map(fn (array $total): array => ['label' => $this->totals->label($total['key']), 'amount' => $total['amount']], $this->totals->for($voucher)),
            bankAccount: $this->bankAccount($voucher),
            approvedBy: $voucher->approver?->name,
            approvedAt: $voucher->approved_at?->format('Y-m-d H:i'),
            paidOn: $voucher->paid_on?->toDateString(),
            paidReference: $voucher->paid_reference,
            canApprove: $voucher->status->canTransitionTo(PayoutVoucherStatus::Approved)
                && ($this->bankAccount($voucher) !== null || ! BigDecimal::of($voucher->amount)->isPositive()),
            canMarkPaid: $voucher->status->canTransitionTo(PayoutVoucherStatus::Paid),
            canAdjust: $voucher->status === PayoutVoucherStatus::Draft,
        );
    }

    /**
     * @return array{bankName: string, accountName: string, accountNumber: string, swiftCode: string|null}|null
     */
    public function bankAccount(PayoutVoucher $voucher): ?array
    {
        if ($voucher->status !== PayoutVoucherStatus::Draft) {
            $saved = $voucher->snapshot['bank_account'] ?? null;

            return is_array($saved) ? [
                'bankName' => (string) $saved['bank_name'],
                'accountName' => (string) $saved['account_name'],
                'accountNumber' => (string) $saved['account_number'],
                'swiftCode' => $saved['swift_code'] ?? null,
            ] : null;
        }

        $account = $voucher->property->bankAccounts()->active()->ordered()->first();

        return $account === null ? null : [
            'bankName' => $account->bank_name,
            'accountName' => $account->account_name,
            'accountNumber' => $account->account_number,
            'swiftCode' => $account->swift_code,
        ];
    }
}
