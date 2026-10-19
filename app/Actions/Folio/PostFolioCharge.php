<?php

namespace App\Actions\Folio;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Folio\QuotedDiscount;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PostFolioCharge
{
    /**
     * Write one charge line on a given folio, then its discount lines and one Tax child line for each tax component,
     * with no checks. The caller checks everything first, calculates the tax with `TaxCalculator` (a refusal must stop
     * before any write) and runs this inside its transaction. The charge line holds the net amount, so the folio total
     * is the gross amount. The `$source` names the unit of the line (see docs/13-folio/02-posting-charges.md#line-sources). A caller that discounts the charge gives `$lineAmount` (the charge line amount, which the
     * tax result cannot know) and its `$discounts`, which post as negative Adjustment children. The `$serviceDate` is the day
     * it really happened (a night's date) and defaults to the posting date; the children share it. Tax keys are
     * `{key}:tax:{tax_id}`. A null actor is the system, as in the night audit. `PostCharge`, `PostRoomCharges` and
     * `RecordPayment` (the payment surcharge) call it. See docs/13-folio/02-posting-charges.md.
     *
     * A `$manualBatchId` marks the charge line only, not its children.
     *
     * @param  list<QuotedDiscount>  $discounts  Each with its transaction code id resolved.
     * @return non-empty-list<FolioLine> the charge line first, then its discount lines, then its tax lines
     */
    public function __invoke(
        ?User $actor,
        Folio $folio,
        TransactionCode $code,
        string $description,
        int $quantity,
        Money $unitAmount,
        TaxCalculationResult $tax,
        string $key,
        string $postingDate,
        ?Model $source = null,
        ?Money $lineAmount = null,
        array $discounts = [],
        ?string $serviceDate = null,
        ?int $manualBatchId = null,
    ): array {
        if (! $tax->isCalculated()) {
            throw new InvalidArgumentException('A refused tax calculation cannot be posted.');
        }

        $serviceDate ??= $postingDate;
        $charge = $this->line($folio, $actor, $postingDate, $serviceDate, $key, [
            'transaction_code_id' => $code->id,
            'kind' => TransactionKind::Charge,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => (string) $unitAmount->getAmount(),
            'amount' => (string) ($lineAmount ?? $tax->netAmount)?->getAmount(),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ], $manualBatchId);
        $lines = [$charge];

        foreach ($discounts as $discount) {
            $lines[] = $this->line($folio, $actor, $postingDate, $serviceDate, $discount->key, [
                'transaction_code_id' => $discount->transactionCodeId,
                'kind' => TransactionKind::Adjustment,
                'description' => $discount->description,
                'quantity' => 1,
                'unit_amount' => (string) $discount->amount->negated()->getAmount(),
                'amount' => (string) $discount->amount->negated()->getAmount(),
                'parent_line_id' => $charge->id,
            ]);
        }

        foreach ($tax->taxes as $component) {
            $lines[] = $this->line($folio, $actor, $postingDate, $serviceDate, $key.':tax:'.$component->taxId, [
                'transaction_code_id' => $component->transactionCodeId,
                'kind' => TransactionKind::Tax,
                'description' => $component->name,
                'quantity' => 1,
                'unit_amount' => (string) $component->amount->getAmount(),
                'amount' => (string) $component->amount->getAmount(),
                'parent_line_id' => $charge->id,
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(Folio $folio, ?User $actor, string $postingDate, string $serviceDate, string $key, array $attributes, ?int $manualBatchId = null): FolioLine
    {
        $line = new FolioLine($attributes);
        $line->folio_id = $folio->id;
        $line->posting_date = $postingDate;
        $line->service_date = $serviceDate;
        $line->idempotency_key = $key;
        $line->manual_batch_id = $manualBatchId;
        $line->user_id = $actor?->id;
        $line->save();

        return $line->refresh();
    }
}
