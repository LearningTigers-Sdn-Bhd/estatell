<?php

namespace App\Actions\Folio;

use App\Data\Folio\DiscountQuoteData;
use App\Enums\FolioStatus;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Folio\DiscountQuote;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class ApplyDiscount
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private DiscountQuote $discountQuote,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Post a negative discount line under each eligible charge, then a negative tax line under each discount line for
     * every percent tax line of the charge (tax x share / net amount). Flat taxes are never reduced and
     * `TaxCalculator` is not called. The folio row is locked first, so two applications never race, and the unique
     * ledger key is the second guard. Every check runs before the first write, so a refusal writes and logs nothing.
     * Permissions are checked by the route. See docs/13-folio/04-folio-discounts.md.
     */
    public function __invoke(User $actor, Folio $folio, Discount $discount, ?string $amount, string $fingerprint): PostingResult
    {
        return DB::transaction(function () use ($actor, $folio, $discount, $amount, $fingerprint): PostingResult {
            $locked = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $current = Discount::query()->where('property_id', $locked->property_id)->active()->find($discount->id);

            if ($current === null) {
                return PostingResult::refused(PostingResult::REFUSAL_DISCOUNT_NOT_FOUND);
            }

            if ($locked->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $quote = $this->discountQuote->quote($locked, $current, $businessDate, $amount);

            if ($quote->lines === []) {
                return PostingResult::refused(PostingResult::REFUSAL_NOTHING_ELIGIBLE);
            }

            if ($quote->amountRefusal !== null) {
                return PostingResult::refused($quote->amountRefusal);
            }

            if (! hash_equals($quote->fingerprint, $fingerprint)) {
                return PostingResult::refused(PostingResult::REFUSAL_QUOTE_CHANGED);
            }

            $date = $businessDate->toDateString();
            $currency = $locked->property->settings->currency->getCurrencyCode();
            $lines = $this->post($actor, $locked, $current, $quote, $date, $currency);

            if ($lines === []) {
                return PostingResult::refused(PostingResult::REFUSAL_NOTHING_ELIGIBLE);
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('folio.discount_applied')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'folio_id' => $locked->id,
                    'discount_id' => $current->id,
                    'amount' => $quote->amount,
                    'charge_line_ids' => array_map(fn ($line): int => $line->folioLineId, $quote->lines),
                    'lines' => count($lines),
                    'posting_date' => $date,
                ])
                ->log('Folio discount applied');

            ($this->closeSettledUnitFolio)($actor, $locked);

            return PostingResult::posted($lines);
        });
    }

    /**
     * Lines with a zero share are skipped, so the list can be empty.
     *
     * @return list<FolioLine>
     */
    private function post(User $actor, Folio $folio, Discount $discount, DiscountQuoteData $quote, string $date, string $currency): array
    {
        $lines = [];

        foreach ($quote->lines as $target) {
            $share = Money::of((string) $target->share, $currency);

            if (! $share->isPositive()) {
                continue;
            }

            $charge = FolioLine::query()->findOrFail($target->folioLineId);
            $key = 'discount:'.$discount->id.':'.$charge->id;
            $discountLine = $this->line($actor, $folio, $date, $key, [
                'transaction_code_id' => $discount->transaction_code_id,
                'kind' => TransactionKind::Adjustment,
                'description' => $discount->name,
                'quantity' => 1,
                'unit_amount' => (string) $share->negated()->getAmount(),
                'amount' => (string) $share->negated()->getAmount(),
                'parent_line_id' => $charge->id,
            ]);
            $lines[] = $discountLine;

            foreach ($charge->children()->where('kind', TransactionKind::Tax)->orderBy('id')->get() as $taxLine) {
                $tax = Tax::query()->where('property_id', $folio->property_id)->where('transaction_code_id', $taxLine->transaction_code_id)->first();

                if ($tax === null || $tax->rate_type !== TaxRateType::Percent) {
                    continue;
                }

                $reduction = Money::of(
                    BigDecimal::of($taxLine->amount)->multipliedBy($share->getAmount())->dividedBy($charge->amount, $share->getCurrency()->getDefaultFractionDigits(), RoundingMode::HalfUp),
                    $currency,
                );

                if (! $reduction->isPositive()) {
                    continue;
                }

                $lines[] = $this->line($actor, $folio, $date, $key.':tax:'.$tax->id, [
                    'transaction_code_id' => $taxLine->transaction_code_id,
                    'kind' => TransactionKind::Tax,
                    'description' => $taxLine->description,
                    'quantity' => 1,
                    'unit_amount' => (string) $reduction->negated()->getAmount(),
                    'amount' => (string) $reduction->negated()->getAmount(),
                    'parent_line_id' => $discountLine->id,
                ]);
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(User $actor, Folio $folio, string $postingDate, string $key, array $attributes): FolioLine
    {
        $line = new FolioLine($attributes);
        $line->folio_id = $folio->id;
        $line->posting_date = $postingDate;
        $line->idempotency_key = $key;
        $line->user_id = $actor->id;
        $line->save();

        return $line->refresh();
    }
}
