<?php

namespace App\Services\Commissions;

use App\Data\Commissions\PayoutVoucherRowData;
use App\Data\Commissions\PropertyPayoutSummaryData;
use App\Data\Property\CurrencyData;
use App\Enums\PayoutVoucherStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use Brick\Math\BigDecimal;
use Brick\Money\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reads the property's own Payouts page: whether it shows, the next and last payout, and the vouchers it may see.
 * See docs/30-commission-fees/06-property-payouts.md.
 */
class PropertyPayouts
{
    public const int PER_PAGE = 25;

    public function __construct(private PayoutCycle $payoutCycle) {}

    /**
     * Shown while a commission service is on today, or once the property has a voucher it may see, so past payouts stay
     * readable after every service stops.
     */
    public function isShown(Property $property): bool
    {
        $today = $this->payoutCycle->today()->toDateString();

        return PropertyCommissionPeriod::query()
            ->where('property_id', $property->id)
            ->whereDate('starts_on', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->exists()
            || $property->payoutVouchers()->whereIn('status', PayoutVoucherStatus::visibleToProperty())->exists();
    }

    /**
     * The next payout is the earliest unpaid voucher (a Draft shows only as its amount), or a forecast of the coming
     * cut-off from the entries not yet in a voucher. The last payout is the latest Paid voucher.
     */
    public function summary(Property $property): PropertyPayoutSummaryData
    {
        [$nextAmount, $nextDueOn] = $this->nextPayout($property);
        $last = $property->payoutVouchers()
            ->where('status', PayoutVoucherStatus::Paid)
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->first();

        return new PropertyPayoutSummaryData(
            nextAmount: (string) $nextAmount->toScale(2),
            nextDueOn: $nextDueOn,
            lastAmount: $last?->amount,
            lastPaidOn: $last?->paid_on?->toDateString(),
            lastReference: $last?->paid_reference,
            currency: CurrencyData::fromCurrency(Currency::of('MYR')),
        );
    }

    /**
     * @return LengthAwarePaginator<int, PayoutVoucher>
     */
    public function vouchers(Property $property): LengthAwarePaginator
    {
        return $property->payoutVouchers()
            ->whereIn('status', PayoutVoucherStatus::visibleToProperty())
            ->with(PayoutVoucherRowData::RELATIONS)
            ->orderByDesc('period_end')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return array{0: BigDecimal, 1: string}
     */
    private function nextPayout(Property $property): array
    {
        $open = $property->payoutVouchers()
            ->whereIn('status', [PayoutVoucherStatus::Draft, PayoutVoucherStatus::Approved])
            ->orderBy('due_on')
            ->orderBy('id')
            ->first();

        if ($open !== null) {
            return [BigDecimal::of($open->amount), $open->due_on->toDateString()];
        }

        $cutOff = $this->payoutCycle->currentEndOn($this->payoutCycle->today());
        $forecast = CommissionEntry::query()
            ->where('property_id', $property->id)
            ->whereNull('payout_voucher_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $cutOff->toDateString())
            ->sum('amount');

        return [BigDecimal::of((string) $forecast), $this->payoutCycle->payoutDate($cutOff)->toDateString()];
    }
}
