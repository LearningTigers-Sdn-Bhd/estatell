<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Credit part of a restaurant room charge back to the guest: a negative charge line with negative tax lines, written on
 * the folio the charge sits on. It is the partial-refund way of the POS, next to `ReverseLine::forPos()` for a full one.
 * It checks only that the folio is the charge's own and Open. It skips the checks a sale needs (`no_post`, the departure
 * date, the room charge limit, the signature and the routing rules), so a manager can always give a charge back, even
 * after front desk blocked the stay for room charges. The caller keeps the credit within the refundable balance. The
 * amounts are given as positive magnitudes and posted negative. The same key writes nothing twice. A refusal writes nothing.
 * See docs/34-native-pos/04-tax-checkout-and-pms.md.
 */
class PostPosFolioCredit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PostFolioCharge $postFolioCharge,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * @param  list<CalculatedTax>  $taxes  Positive amounts, posted as credits.
     */
    public function __invoke(?User $actor, FolioLine $rootLine, string $description, Money $net, array $taxes, string $key, Model $source): PostingResult
    {
        return DB::transaction(function () use ($actor, $rootLine, $description, $net, $taxes, $key, $source): PostingResult {
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()]);
            }

            $root = FolioLine::query()->whereNull('parent_line_id')->whereNull('reversal_of_id')->find($rootLine->id);
            $folio = $root === null ? null : $root->folio()->lockForUpdate()->first();

            if ($root === null || $folio === null) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($folio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $creditNet = $net->negated();
            $credits = array_map(fn (CalculatedTax $tax): CalculatedTax => new CalculatedTax($tax->taxId, $tax->transactionCodeId, $tax->name, $tax->amount->negated(), $tax->inclusive), $taxes);
            $gross = array_reduce($credits, fn (Money $total, CalculatedTax $tax): Money => $total->plus($tax->amount), $creditNet);

            $lines = ($this->postFolioCharge)(
                $actor,
                $folio,
                $root->transactionCode,
                $description,
                1,
                $creditNet,
                TaxCalculationResult::calculated($creditNet, $credits, $gross),
                $key,
                $businessDate->toDateString(),
                $source,
            );

            activity()
                ->performedOn($folio->reservation ?? $folio)
                ->causedBy($actor)
                ->event('folio.pos_credit_posted')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $lines[0]->id,
                    'credited_line_id' => $root->id,
                    'amount' => $lines[0]->amount,
                    'posting_date' => $businessDate->toDateString(),
                ])
                ->log('POS credit posted to folio');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
