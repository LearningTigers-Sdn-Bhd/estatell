<?php

namespace App\Actions\Pos;

use App\Actions\Folio\PostCharge;
use App\Data\Folio\NewChargeData;
use App\Data\Pos\PosChargeData;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Pos\PosOutletTaxCheck;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Post one POS receipt to a guest's folio as a single line, "{Outlet} — Receipt #{number}". The key is
 * `pos:{outlet_id}:{receipt_id}`: a receipt that was seen before returns its posting and writes nothing. `PostCharge`
 * does the locking, folio routing, tax extraction and ledger write; the outlet's charge item has only inclusive taxes
 * (see `SavePosOutlet`), so the folio gross equals the receipt. A receipt that cannot post now (no business date, inactive
 * outlet, no matched room, unit not checked in, folio closed, ...) is kept as PendingReview with the reason and the
 * raw receipt, never dropped. An outlet with no charge item is native-only and is refused (`outlet_not_external`), so the
 * external POS API can never crash on it. See docs/33-pos/01-outlets-and-postings.md#postposcharge.
 */
class PostPosCharge
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PostCharge $postCharge,
        private PosOutletTaxCheck $taxCheck,
    ) {}

    public function __invoke(PosOutlet $outlet, PosChargeData $data): PosPostingResult
    {
        if ($outlet->default_charge_item_id === null) {
            return PosPostingResult::refused(PosPostingResult::REFUSAL_OUTLET_NOT_EXTERNAL);
        }

        try {
            return $this->post($outlet, $data);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent duplicate won the race and the transaction rolled back, so its posting is the answer.
            return PosPostingResult::replayed($this->existing($outlet, $data->externalReceiptId) ?? throw $exception);
        }
    }

    private function existing(PosOutlet $outlet, string $externalReceiptId): ?PosPosting
    {
        return PosPosting::query()
            ->where('pos_outlet_id', $outlet->id)
            ->where('external_receipt_id', $externalReceiptId)
            ->where('posting_type', PosPostingType::Charge)
            ->first();
    }

    private function post(PosOutlet $outlet, PosChargeData $data): PosPostingResult
    {
        return DB::transaction(function () use ($outlet, $data): PosPostingResult {
            $existing = $this->existing($outlet, $data->externalReceiptId);

            if ($existing !== null) {
                return PosPostingResult::replayed($existing);
            }

            $outlet->loadMissing(['property', 'defaultChargeItem']);
            $item = $outlet->defaultChargeItem;
            $unit = $data->reservationUnitId === null
                ? null
                : ReservationUnit::query()->where('property_id', $outlet->property_id)->find($data->reservationUnitId);
            $lineId = null;
            $reason = null;

            if ($this->currentBusinessDate->date($outlet->property) === null) {
                $reason = 'no_business_date';
            } elseif (! $outlet->active || ! $item->active) {
                $reason = 'outlet_inactive';
            } elseif ($this->taxCheck->hasExclusiveTax($outlet->property, $item->transaction_code_id)) {
                $reason = 'exclusive_tax_not_allowed';
            } elseif ($unit === null) {
                $reason = $data->failureHint ?? 'room_not_matched';
            } else {
                $result = ($this->postCharge)(null, $unit, NewChargeData::from([
                    'chargeItemId' => null,
                    'transactionCodeId' => $item->transaction_code_id,
                    'description' => self::description($outlet, $data->externalReceiptNumber),
                    'quantity' => 1,
                    'unitAmount' => $data->amount,
                    'idempotencyKey' => self::key($outlet, $data->externalReceiptId),
                    'serviceDate' => $data->serviceDate,
                ]));

                $reason = $result->refusal;
                $lineId = $result->line()?->id;
            }

            $posting = new PosPosting([
                'pos_outlet_id' => $outlet->id,
                'reservation_unit_id' => $unit?->id,
                'folio_line_id' => $lineId,
                'external_receipt_id' => $data->externalReceiptId,
                'external_receipt_number' => $data->externalReceiptNumber,
                'posting_type' => PosPostingType::Charge,
                'status' => $reason === null ? PosPostingStatus::Posted : PosPostingStatus::PendingReview,
                'amount' => $data->amount,
                'failure_reason' => $reason,
                'raw_payload' => $data->rawPayload,
                'posted_at' => $reason === null ? now() : null,
            ]);
            $posting->property_id = $outlet->property_id;
            $posting->save();

            activity()
                ->performedOn($posting)
                ->event($reason === null ? 'pos.charge_posted' : 'pos.posting_pending_review')
                ->withProperties(['property_id' => $outlet->property_id, 'pos_outlet_id' => $outlet->id, 'amount' => $data->amount, 'failure_reason' => $reason])
                ->log($reason === null ? 'POS charge posted' : 'POS charge needs review');

            return PosPostingResult::done($posting);
        });
    }

    public static function key(PosOutlet $outlet, string $externalReceiptId): string
    {
        return 'pos:'.$outlet->id.':'.$externalReceiptId;
    }

    public static function description(PosOutlet $outlet, string $receiptNumber): string
    {
        return $outlet->name.' — Receipt #'.$receiptNumber;
    }
}
