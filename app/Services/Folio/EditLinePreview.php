<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostingResult;
use App\Data\Folio\EditLineData;
use App\Data\Folio\EditLinePreviewData;
use App\Data\Folio\ManualTaxData;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Services\Catalogues\TaxCalculator;
use Brick\Money\Money;

/**
 * Reads one charge and the values typed for it and decides what the Edit line Dialog would post. `plan()` is the one
 * place that checks and calculates, for the preview and for `EditLine`. A live room night is not edited here: its price
 * changes through Change price. It writes nothing. See docs/13-folio/08-manual-folio.md#edit-line.
 */
class EditLinePreview
{
    public const int MAX_DESCRIPTION = 120;

    public function __construct(
        private LineTaxes $lineTaxes,
        private TaxCalculator $taxCalculator,
        private FolioRefusals $refusals,
    ) {}

    /**
     * Whether the line is the room line of a unit night (`night:{id}` or `night:{id}:fix:{n}`), which has no free price.
     */
    public function nightId(FolioLine $line): ?int
    {
        return preg_match('/^night:(\d+)(?::fix:\d+)?$/', (string) $line->idempotency_key, $match) === 1 ? (int) $match[1] : null;
    }

    public function plan(FolioLine $line, Folio $folio, EditLineData $edit): EditLinePlan
    {
        $currency = $folio->property->settings->currency->getCurrencyCode();
        $live = $this->lineTaxes->liveTaxLines($line);
        $beforeNet = Money::of($line->amount, $currency);
        $before = $live->reduce(fn (Money $sum, FolioLine $tax): Money => $sum->plus($tax->amount), $beforeNet);
        $refuse = fn (string $code): EditLinePlan => new EditLinePlan($code, $before, $beforeNet);
        $refusal = $this->lineTaxes->chargeRefusal($line, $folio);

        if ($refusal !== null) {
            return $refuse($refusal);
        }

        if ($this->nightId($line) !== null) {
            return $refuse(PostingResult::REFUSAL_NIGHT_LINE);
        }

        $description = trim($edit->description);

        if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION) {
            return $refuse(PostingResult::REFUSAL_EDIT_INVALID);
        }

        if ($edit->quantity < 1 || $edit->quantity > 999) {
            return $refuse(PostingResult::REFUSAL_QUANTITY_INVALID);
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $edit->amount) !== 1 || (float) $edit->amount <= 0) {
            return $refuse(PostingResult::REFUSAL_AMOUNT_INVALID);
        }

        $ids = array_values(array_unique($edit->taxIds));
        $taxes = $folio->property->taxes()->whereKey($ids)->get();

        if ($taxes->count() !== count($ids)) {
            return $refuse(PostingResult::REFUSAL_TAX_NOT_AVAILABLE);
        }

        $unitAmount = Money::of($edit->amount, $currency);
        $tax = $this->taxCalculator->calculateWithTaxes($folio->property, $taxes, $unitAmount->multipliedBy($edit->quantity), (string) $edit->quantity, $this->lineTaxes->nationality($line));

        if (! $tax->isCalculated()) {
            return $refuse((string) $tax->refusalReason);
        }

        $current = $live->map(fn (FolioLine $taxLine): ?int => $this->lineTaxes->taxOf($taxLine, $folio->property_id)?->id)->filter()->sort()->values()->all();
        $same = $description === $line->description
            && Money::of($line->unit_amount, $currency)->isEqualTo($unitAmount)
            && $edit->quantity === (int) $line->quantity
            && $current === collect($ids)->sort()->values()->all();

        return $same ? $refuse(PostingResult::REFUSAL_NOTHING_CHANGED) : new EditLinePlan(null, $before, $beforeNet, $unitAmount, $tax);
    }

    public function preview(FolioLine $line, Folio $folio, EditLineData $edit): EditLinePreviewData
    {
        $plan = $this->plan($line, $folio, $edit);
        $text = fn (Money $money): string => (string) $money->getAmount();
        $beforeTax = $plan->before->minus($plan->beforeNet);

        if ($plan->refusal !== null || $plan->tax === null) {
            $errors = $plan->refusal === null ? [FolioRefusals::FORM => ''] : $this->refusals->errors($plan->refusal);
            $field = (string) array_key_first($errors);
            $message = $errors[$field];

            return new EditLinePreviewData($text($plan->beforeNet), $text($beforeTax), $text($plan->before), null, null, null, null, [], $message, $field);
        }

        $after = $plan->tax->grossAmount;
        $afterTax = $after->minus($plan->tax->netAmount);

        return new EditLinePreviewData(
            $text($plan->beforeNet),
            $text($beforeTax),
            $text($plan->before),
            $text($plan->tax->netAmount),
            $text($afterTax),
            $text($after),
            $text($after->minus($plan->before)),
            array_map(fn ($component): ManualTaxData => new ManualTaxData($component->name, $text($component->amount), false), $plan->tax->taxes),
            null,
            null,
        );
    }
}
