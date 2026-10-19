<?php

namespace App\Actions\Folio;

use App\Actions\Payments\RefundPayment;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\User;
use App\Services\Folio\OldFolioLines;
use Brick\Math\BigDecimal;

class ReverseMigratedLines
{
    public function __construct(
        private OldFolioLines $oldFolioLines,
        private ReverseLine $reverseLine,
        private RefundPayment $refundPayment,
    ) {}

    /**
     * Take back every live line that the old folio page saved on a folio, for the switch to One amount (an opening
     * balance is posted on a folio that holds copied lines). Staff never see this step. Returns the number of lines
     * taken back. It writes inside the caller's transaction and checks nothing else: the caller has locked an Open folio
     * and checked the migration window. A refused refund throws `SaveOldFoliosRefused`, which rolls the caller's
     * transaction back. See docs/12-reservations/12-past-and-migrated-stays.md#old-folio.
     */
    public function __invoke(User $actor, Folio $folio, string $postingDate): int
    {
        $live = $this->oldFolioLines->live($folio->property_id, [$folio->id]);

        foreach ($live as $key => $entry) {
            $this->one($actor, $entry['line'], $entry['payment'], $postingDate, $key);
        }

        return count($live);
    }

    /**
     * Take back one live saved line: a charge or a Tax line gets a reversal (`reversal_of_id`), an Old system payment
     * a refund to Old system. Neither moves money. The refund key is `refund:migrated-{line id}`, so a repeat is a replay.
     */
    public function one(User $actor, FolioLine $line, ?Payment $payment, string $postingDate, string $rowKey): void
    {
        if ($payment === null) {
            $this->reverseLine->post($actor, $line, $postingDate, null);

            return;
        }

        $left = BigDecimal::of($payment->amount)->toScale(2);
        $result = ($this->refundPayment)($actor, $payment, (string) $left, null, 'migrated-'.$line->id);

        if ($result->isRefused()) {
            throw new SaveOldFoliosRefused(SaveOldFoliosResult::refused((string) $result->refusal, $rowKey));
        }
    }
}
