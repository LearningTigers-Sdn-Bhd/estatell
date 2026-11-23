<?php

namespace App\Actions\Pos;

use App\Actions\Folio\ReverseLine;
use App\Data\Pos\PosVoidData;
use App\Enums\FolioStatus;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Void or refund an earlier POS receipt. A void seen before returns its row. A full void of a Posted charge on an Open
 * folio is reversed by `ReverseLine` with no actor (the system). A void of a PendingReview charge ignores it ("Voided
 * before posting"). Everything staff must handle by hand lands as a PendingReview void row with a reason: a partial
 * refund (`partial_refund_manual_review`), a refund above the charge (`refund_amount_mismatch`), a missing original (`original_not_found`), an original that is not Posted
 * (`original_not_posted`), a closed folio (`folio_closed_cannot_void`) or a refused reversal (its refusal code).
 * See docs/33-pos/01-outlets-and-postings.md#voidposcharge.
 */
class VoidPosCharge
{
    public function __construct(private ReverseLine $reverseLine) {}

    public function __invoke(PosOutlet $outlet, PosVoidData $data): PosPostingResult
    {
        try {
            return $this->void($outlet, $data);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent duplicate won the race and the transaction rolled back, so its void row is the answer.
            return PosPostingResult::replayed($this->existing($outlet, $data->externalReceiptId) ?? throw $exception);
        }
    }

    private function existing(PosOutlet $outlet, string $externalReceiptId): ?PosPosting
    {
        return PosPosting::query()
            ->where('pos_outlet_id', $outlet->id)
            ->where('external_receipt_id', $externalReceiptId)
            ->where('posting_type', PosPostingType::Void)
            ->first();
    }

    private function void(PosOutlet $outlet, PosVoidData $data): PosPostingResult
    {
        return DB::transaction(function () use ($outlet, $data): PosPostingResult {
            $existing = $this->existing($outlet, $data->externalReceiptId);

            if ($existing !== null) {
                return PosPostingResult::replayed($existing);
            }

            $original = PosPosting::query()
                ->where('pos_outlet_id', $outlet->id)
                ->where('external_receipt_id', $data->originalExternalReceiptId)
                ->where('posting_type', PosPostingType::Charge)
                ->lockForUpdate()
                ->first();

            $status = PosPostingStatus::PendingReview;
            $reason = null;
            $reversalId = null;

            if ($original === null) {
                $reason = 'original_not_found';
            } elseif ($data->amount !== null && BigDecimal::of($data->amount)->isLessThan($original->amount)) {
                $reason = 'partial_refund_manual_review';
            } elseif ($data->amount !== null && BigDecimal::of($data->amount)->isGreaterThan($original->amount)) {
                $reason = 'refund_amount_mismatch';
            } elseif ($original->status === PosPostingStatus::PendingReview) {
                $original->forceFill([
                    'status' => PosPostingStatus::Ignored,
                    'resolved_at' => now(),
                    'resolution_note' => 'Voided before posting',
                ])->save();
                $status = PosPostingStatus::Reversed;
            } elseif ($original->status !== PosPostingStatus::Posted || $original->folio_line_id === null) {
                $reason = 'original_not_posted';
            } else {
                $line = $original->folioLine()->with('folio')->first();

                if ($line === null || $line->folio->status !== FolioStatus::Open) {
                    $reason = 'folio_closed_cannot_void';
                } else {
                    $result = ($this->reverseLine)(null, $line);

                    if ($result->isRefused()) {
                        $reason = $result->refusal;
                    } else {
                        $original->forceFill(['status' => PosPostingStatus::Reversed])->save();
                        $reversalId = $result->lines[0]->id;
                        $status = PosPostingStatus::Reversed;
                    }
                }
            }

            $posting = new PosPosting([
                'pos_outlet_id' => $outlet->id,
                'pos_connection_id' => $data->posConnectionId,
                'reservation_unit_id' => $original?->reservation_unit_id,
                'folio_line_id' => $reversalId,
                'external_receipt_id' => $data->externalReceiptId,
                'external_receipt_number' => $original->external_receipt_number ?? $data->originalExternalReceiptId,
                'posting_type' => PosPostingType::Void,
                'status' => $status,
                'amount' => $data->amount ?? $original->amount ?? '0.00',
                'failure_reason' => $reason,
                'raw_payload' => $data->rawPayload,
                'posted_at' => $status === PosPostingStatus::Reversed ? now() : null,
                'resolution_note' => $data->reason,
            ]);
            $posting->property_id = $outlet->property_id;
            $posting->save();

            activity()
                ->performedOn($posting)
                ->event($reason === null ? 'pos.charge_voided' : 'pos.posting_pending_review')
                ->withProperties(['property_id' => $outlet->property_id, 'pos_outlet_id' => $outlet->id, 'original_receipt_id' => $data->originalExternalReceiptId, 'failure_reason' => $reason])
                ->log($reason === null ? 'POS charge voided' : 'POS void needs review');

            return PosPostingResult::done($posting);
        });
    }
}
