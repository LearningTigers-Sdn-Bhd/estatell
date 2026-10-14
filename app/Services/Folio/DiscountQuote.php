<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostingResult;
use App\Data\Folio\DiscountQuoteData;
use App\Data\Folio\DiscountQuoteLineData;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reads a folio and quotes a discount: which charges it targets, the subtotal, the amount, each charge's share and a
 * fingerprint. It writes nothing. `ApplyDiscount` quotes again inside its transaction and compares fingerprints.
 * See docs/13-folio/04-folio-discounts.md.
 */
class DiscountQuote
{
    /**
     * `$amount` is what staff entered (a decimal string), or null. A Percent discount ignores it. A closed or voided
     * folio has no eligible charges.
     */
    public function quote(Folio $folio, Discount $discount, CarbonImmutable $businessDate, ?string $amount = null): DiscountQuoteData
    {
        $currency = $folio->property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a discount.');
        $charges = $this->eligibleCharges($folio, $discount, $businessDate);
        $subtotal = $charges->reduce(fn (Money $sum, FolioLine $line): Money => $sum->plus($line->amount), Money::zero($currency));
        [$amount, $refusal] = $this->resolveAmount($discount, $amount, $subtotal);
        $shares = $refusal === null && ($amount !== null || $discount->type === DiscountType::Percent) ? $this->shares($discount, $charges, $subtotal, (string) $amount, $currency) : [];

        return new DiscountQuoteData(
            lines: $charges->map(fn (FolioLine $line): DiscountQuoteLineData => DiscountQuoteLineData::fromLine($line, isset($shares[$line->id]) ? (string) $shares[$line->id]->getAmount() : null))->all(),
            subtotal: (string) $subtotal->getAmount(),
            amount: $refusal === null && $discount->type === DiscountType::Percent ? (string) array_reduce($shares, fn (Money $sum, Money $share): Money => $sum->plus($share), Money::zero($currency))->getAmount() : $amount,
            amountRefusal: $refusal,
            fingerprint: $this->fingerprint($discount, $businessDate, $charges),
        );
    }

    /**
     * Charges that carry no discount yet and match the scope, in line-ID order. A charge is not eligible for a
     * discount whose key `discount:{id}:{line}` was already used, even when that discount was removed again,
     * because the ledger key is unique.
     *
     * @return Collection<int, FolioLine>
     */
    private function eligibleCharges(Folio $folio, Discount $discount, CarbonImmutable $businessDate): Collection
    {
        if ($folio->status !== FolioStatus::Open) {
            return new Collection;
        }

        return FolioLine::query()
            ->where('folio_id', $folio->id)
            ->where('kind', TransactionKind::Charge)
            ->whereNull('parent_line_id')
            ->whereNull('reversal_of_id')
            ->where('amount', '>', 0)
            ->whereDate('posting_date', '<=', $businessDate->toDateString())
            ->whereDoesntHave('reversal')
            ->whereDoesntHave('children', fn ($children) => $children
                ->where('kind', TransactionKind::Adjustment)
                ->whereDoesntHave('reversal'))
            ->whereNotExists(fn ($used) => $used->selectRaw('1')->from('folio_lines as used')
                ->whereRaw("used.idempotency_key = 'discount:".(int) $discount->id.":' || folio_lines.id::text"))
            ->whereHas('transactionCode', fn ($code) => match ($discount->scope) {
                DiscountScope::RoomOnly => $code->whereIn('system_key', SystemTransactionCode::roomRevenueKeys()),
                DiscountScope::AllCharges => $code->where('kind', TransactionKind::Charge),
                DiscountScope::Selected => $code->whereIn('id', $discount->selectedTransactionCodes()->select('transaction_codes.id')),
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * The amount to use and the refusal code for it. Percent has no entered amount. Fixed falls back to its value.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAmount(Discount $discount, ?string $given, Money $subtotal): array
    {
        if ($discount->type === DiscountType::Percent) {
            return [null, null];
        }

        $amount = $given ?? ($discount->type === DiscountType::Fixed ? $discount->value : null);

        if ($amount === null) {
            return [null, PostingResult::REFUSAL_AMOUNT_REQUIRED];
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || BigDecimal::of($amount)->isLessThanOrEqualTo(0) || BigDecimal::of($amount)->isGreaterThan($subtotal->getAmount())) {
            return [$amount, PostingResult::REFUSAL_AMOUNT_INVALID];
        }

        if ($given !== null && $discount->type === DiscountType::Fixed && ! $discount->allow_override && ! BigDecimal::of($given)->isEqualTo($discount->value)) {
            return [$amount, PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED];
        }

        return [(string) BigDecimal::of($amount)->toScale(2), null];
    }

    /**
     * Each charge's share, keyed by line ID. Percent takes the percentage of each net amount. Fixed and Open split the
     * amount in proportion to the net amounts, and the last charge takes the rounding remainder.
     *
     * @param  Collection<int, FolioLine>  $charges
     * @return array<int, Money>
     */
    private function shares(Discount $discount, Collection $charges, Money $subtotal, string $amount, string $currency): array
    {
        $shares = [];

        if ($discount->type === DiscountType::Percent) {
            $rate = BigDecimal::of((string) $discount->value)->dividedBy(100, 8);

            foreach ($charges as $line) {
                $shares[$line->id] = Money::of($line->amount, $currency)->multipliedBy($rate, RoundingMode::HalfUp);
            }

            return $shares;
        }

        $total = Money::of($amount, $currency);
        $rest = $total;
        $digits = $total->getCurrency()->getDefaultFractionDigits();

        foreach ($charges as $index => $line) {
            $shares[$line->id] = $index === $charges->count() - 1
                ? $rest
                : Money::of(BigDecimal::of($amount)->multipliedBy($line->amount)->dividedBy($subtotal->getAmount(), $digits, RoundingMode::HalfUp), $currency);
            $rest = $rest->minus($shares[$line->id]);
        }

        return $shares;
    }

    /**
     * @param  Collection<int, FolioLine>  $charges
     */
    private function fingerprint(Discount $discount, CarbonImmutable $businessDate, Collection $charges): string
    {
        return hash('sha256', (string) json_encode([
            'discount' => [
                $discount->id,
                $discount->updated_at?->format('Y-m-d H:i:s.u'),
                $discount->type->value,
                $discount->value,
                $discount->scope->value,
                $discount->selectedTransactionCodes()->pluck('transaction_codes.id')->sort()->values()->all(),
            ],
            'business_date' => $businessDate->toDateString(),
            'lines' => $charges->map(fn (FolioLine $line): array => [$line->id, $line->amount])->all(),
        ]));
    }
}
