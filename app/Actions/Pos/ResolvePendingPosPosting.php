<?php

namespace App\Actions\Pos;

use App\Actions\Folio\PostCharge;
use App\Data\Folio\NewChargeData;
use App\Data\Pos\ResolvePosPostingData;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Models\PosPosting;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Pos\PosOutletTaxCheck;
use Illuminate\Support\Facades\DB;

/**
 * Settle a PendingReview posting. `attach` posts the receipt to a unit of the property (and optionally one of its open
 * folios) with the same key and description as an automatic posting; `ignore` needs a note. Only a charge can be
 * attached. A refusal from the posting (closed folio, unit not postable, ...) is returned as it is and nothing is
 * written. See docs/33-pos/01-outlets-and-postings.md#resolvependingposposting.
 */
class ResolvePendingPosPosting
{
    public function __construct(private PostCharge $postCharge, private PosOutletTaxCheck $taxCheck) {}

    public function __invoke(User $actor, PosPosting $posting, ResolvePosPostingData $data): PosPostingResult
    {
        return DB::transaction(function () use ($actor, $posting, $data): PosPostingResult {
            $current = PosPosting::query()->lockForUpdate()->findOrFail($posting->id);

            if ($current->status !== PosPostingStatus::PendingReview) {
                return PosPostingResult::refused(PosPostingResult::REFUSAL_NOT_PENDING);
            }

            $note = filled($data->note) ? trim((string) $data->note) : null;

            if ($data->action === 'ignore') {
                if ($note === null) {
                    return PosPostingResult::refused(PosPostingResult::REFUSAL_NOTE_REQUIRED);
                }

                $current->forceFill([
                    'status' => PosPostingStatus::Ignored,
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_note' => $note,
                ])->save();

                $this->log($actor, $current, 'pos.posting_ignored', 'POS posting ignored');

                return PosPostingResult::done($current);
            }

            if ($data->action !== 'attach') {
                return PosPostingResult::refused(PosPostingResult::REFUSAL_ACTION_INVALID);
            }

            if ($current->posting_type !== PosPostingType::Charge) {
                return PosPostingResult::refused(PosPostingResult::REFUSAL_ATTACH_NOT_SUPPORTED);
            }

            $unit = $data->reservationUnitId === null
                ? null
                : ReservationUnit::query()->where('property_id', $current->property_id)->find($data->reservationUnitId);

            if ($unit === null) {
                return PosPostingResult::refused(PosPostingResult::REFUSAL_UNIT_NOT_FOUND);
            }

            $outlet = $current->outlet()->with(['defaultChargeItem', 'property'])->firstOrFail();

            if ($this->taxCheck->hasExclusiveTax($outlet->property, $outlet->defaultChargeItem->transaction_code_id)) {
                return PosPostingResult::refused('exclusive_tax_not_allowed');
            }

            $result = ($this->postCharge)($actor, $unit, NewChargeData::from([
                'chargeItemId' => null,
                'transactionCodeId' => $outlet->defaultChargeItem->transaction_code_id,
                'description' => PostPosCharge::description($outlet, $current->external_receipt_number),
                'quantity' => 1,
                'unitAmount' => $current->amount,
                'idempotencyKey' => PostPosCharge::key($outlet, $current->external_receipt_id),
                'folioId' => $data->folioId,
            ]));

            if ($result->isRefused()) {
                return PosPostingResult::refused((string) $result->refusal);
            }

            $current->forceFill([
                'status' => PosPostingStatus::Posted,
                'reservation_unit_id' => $unit->id,
                'folio_line_id' => $result->line()?->id,
                'failure_reason' => null,
                'posted_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            $this->log($actor, $current, 'pos.posting_resolved', 'POS posting attached to folio');

            return PosPostingResult::done($current);
        });
    }

    private function log(User $actor, PosPosting $posting, string $event, string $message): void
    {
        activity()
            ->performedOn($posting)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['property_id' => $posting->property_id, 'amount' => $posting->amount, 'reservation_unit_id' => $posting->reservation_unit_id])
            ->log($message);
    }
}
