<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class RemoveDiscount
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ReverseLine $reverseLine,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Reverse a discount line and its tax lines. The charge stays. A discount line is an Adjustment line under a
     * charge line. The folio row is locked first, and every check runs before the first write, so a refusal writes and
     * logs nothing. Permissions are checked by the route. See docs/13-folio/04-folio-discounts.md.
     */
    public function __invoke(User $actor, FolioLine $discountLine): PostingResult
    {
        return DB::transaction(function () use ($actor, $discountLine): PostingResult {
            $current = FolioLine::query()->findOrFail($discountLine->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);
            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $charge = $current->parent;

            if ($current->kind !== TransactionKind::Adjustment || $current->reversal_of_id !== null || $charge === null || $charge->kind !== TransactionKind::Charge) {
                return PostingResult::refused(PostingResult::REFUSAL_NOT_A_DISCOUNT);
            }

            if ($current->reversal()->exists() || $charge->reversal()->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            if ($folio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $lines = $this->reverseLine->post($actor, $current, $businessDate->toDateString(), null);

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.discount_removed')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'charge_line_id' => $charge->id,
                    'reversal_line_id' => $lines[0]->id,
                    'amount' => $lines[0]->amount,
                    'posting_date' => $businessDate->toDateString(),
                ])
                ->log('Folio discount removed');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
