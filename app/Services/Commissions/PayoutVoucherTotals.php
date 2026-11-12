<?php

namespace App\Services\Commissions;

use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use Brick\Math\BigDecimal;

/**
 * A voucher's lines summed by kind: each entry type, with commission split by service. Labels are worked out on read.
 * See docs/30-commission-fees/05-platform-pages.md#voucher-page.
 */
class PayoutVoucherTotals
{
    /**
     * @return list<array{key: string, amount: string}> In entry type order, commission per service.
     */
    public function for(PayoutVoucher $voucher): array
    {
        $sums = [];

        foreach ($voucher->entries()->get(['type', 'service', 'amount']) as $entry) {
            $key = $this->key($entry);
            $sums[$key] = ($sums[$key] ?? BigDecimal::zero())->plus($entry->amount);
        }

        $order = array_map(fn (CommissionEntryType $type): string => $type->value, CommissionEntryType::cases());
        uksort($sums, fn (string $a, string $b): int => [array_search(strtok($a, ':'), $order, true), $a] <=> [array_search(strtok($b, ':'), $order, true), $b]);

        return array_map(
            fn (string $key, BigDecimal $sum): array => ['key' => $key, 'amount' => (string) $sum->toScale(2)],
            array_keys($sums),
            $sums,
        );
    }

    /**
     * `commission:booking_engine` for commission, the type value for the rest.
     */
    public function key(CommissionEntry $entry): string
    {
        return $entry->type === CommissionEntryType::Commission && $entry->service !== null
            ? $entry->type->value.':'.$entry->service->value
            : $entry->type->value;
    }

    public function label(string $key): string
    {
        [$type, $service] = array_pad(explode(':', $key, 2), 2, null);

        return $service === null
            ? CommissionEntryType::from($type)->label()
            : __(':service commission', ['service' => CommissionService::from($service)->label()]);
    }
}
