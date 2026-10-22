<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Sets the figures of one replayed day in estatell beside the figures of its wastays night audit
 * (`night_audit_financial_summaries`): room revenue, tax, payments, refunds, adjustments and no-show charges. The
 * estatell figures are the folio lines posted on the business date. It reads only.
 * See docs/migration-plan/grand-eastern-hotel.md "Dry run".
 */
final class WastaysDayCompare
{
    /** @var list<string> */
    public const array FIGURES = ['room_revenue', 'tax_revenue', 'payments_total', 'refunds_total', 'adjustments_total', 'no_show_charges'];

    /**
     * @return array<string, string>|null the wastays figures of the date, or null when the bundle has none
     */
    public function wastays(WastaysBundle $bundle, WastaysDay $day, string $date): ?array
    {
        $audit = $day->nightAudit($date);

        if ($audit === null || ! array_key_exists('night_audit_financial_summaries.csv', $bundle->manifest->files)) {
            return null;
        }

        $summary = $bundle->rows('night_audit_financial_summaries')->first(fn (array $row): bool => $row['night_audit_id'] === $audit['id']);

        if ($summary === null) {
            return null;
        }

        return array_combine(self::FIGURES, array_map(fn (string $figure): string => $this->decimal((string) $summary[$figure]), self::FIGURES));
    }

    /**
     * @return array<string, string>
     */
    public function estatell(Property $property, string $date): array
    {
        $day = fn () => FolioLine::query()->whereHas('folio', fn ($folios) => $folios->where('property_id', $property->id))->whereDate('posting_date', $date);
        $rooms = array_map(fn (SystemTransactionCode $code): string => $code->value, array_filter(SystemTransactionCode::roomRevenueCases(), fn (SystemTransactionCode $code): bool => $code !== SystemTransactionCode::NoShowRevenue));
        // wastays keeps security deposits in their own table, outside its payment and refund totals.
        $money = fn () => $day()->where('kind', TransactionKind::Payment)->whereDoesntHave('payment', fn ($payments) => $payments->where('purpose', PaymentPurpose::SecurityDeposit));
        $byCode = fn (array $keys) => $day()->where('kind', TransactionKind::Charge)->whereHas('transactionCode', fn ($codes) => $codes->whereIn('system_key', $keys));

        return array_map($this->decimal(...), [
            'room_revenue' => (string) $byCode($rooms)->sum('amount'),
            'tax_revenue' => (string) $day()->where('kind', TransactionKind::Tax)->sum('amount'),
            'payments_total' => (string) abs((float) $money()->where('amount', '<', 0)->sum('amount')),
            'refunds_total' => (string) $money()->where('amount', '>', 0)->sum('amount'),
            'adjustments_total' => (string) $day()->where('kind', TransactionKind::Adjustment)->sum('amount'),
            'no_show_charges' => (string) $byCode([SystemTransactionCode::NoShowRevenue->value])->sum('amount'),
        ]);
    }

    /**
     * The figures that differ.
     *
     * @param  array<string, string>  $wastays
     * @param  array<string, string>  $estatell
     * @return list<array{figure: string, wastays: string, estatell: string}>
     */
    public function differences(array $wastays, array $estatell): array
    {
        $differences = [];

        foreach (self::FIGURES as $figure) {
            if (! BigDecimal::of($wastays[$figure])->isEqualTo($estatell[$figure])) {
                $differences[] = ['figure' => $figure, 'wastays' => $wastays[$figure], 'estatell' => $estatell[$figure]];
            }
        }

        return $differences;
    }

    private function decimal(string $value): string
    {
        return (string) BigDecimal::of($value === '' ? '0' : $value)->toScale(2, RoundingMode::HalfUp);
    }
}
