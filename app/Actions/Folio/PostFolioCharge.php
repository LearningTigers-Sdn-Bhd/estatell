<?php

namespace App\Actions\Folio;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculationResult;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PostFolioCharge
{
    /**
     * Write one charge line on a given folio, then one Tax child line for each tax component, with no checks. The
     * caller checks everything first, calculates the tax with `TaxCalculator` (a refusal must stop before any write)
     * and runs this inside its transaction. The charge line holds the net amount, so the folio total is the gross
     * amount. Tax keys are `{key}:tax:{tax_id}`. `PostCharge` and `RecordPayment` (the payment surcharge) call it.
     * See docs/13-folio/02-posting-charges.md.
     *
     * @return non-empty-list<FolioLine> the charge line first, then its tax lines
     */
    public function __invoke(
        User $actor,
        Folio $folio,
        TransactionCode $code,
        string $description,
        int $quantity,
        Money $unitAmount,
        TaxCalculationResult $tax,
        string $key,
        string $postingDate,
        ?Model $source = null,
    ): array {
        if (! $tax->isCalculated()) {
            throw new InvalidArgumentException('A refused tax calculation cannot be posted.');
        }

        $charge = $this->line($folio, $actor, $postingDate, $key, [
            'transaction_code_id' => $code->id,
            'kind' => TransactionKind::Charge,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => (string) $unitAmount->getAmount(),
            'amount' => (string) $tax->netAmount?->getAmount(),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ]);
        $lines = [$charge];

        foreach ($tax->taxes as $component) {
            $lines[] = $this->line($folio, $actor, $postingDate, $key.':tax:'.$component->taxId, [
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
    private function line(Folio $folio, User $actor, string $postingDate, string $key, array $attributes): FolioLine
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
