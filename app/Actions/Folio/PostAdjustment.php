<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioPostability;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PostAdjustment
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioPostability $folioPostability,
    ) {}

    /**
     * Post one signed amount with the system Adjustment code on a folio, with no tax line. A positive amount raises the
     * balance, a negative one lowers it. It is for a correction that is not a discount of one charge: the wastays
     * replay uses it for adjustment lines. The folio row is locked first, and every check runs before the write, so a
     * refusal writes and logs nothing. `posting_date` is the business date and `service_date` defaults to it. The key
     * makes a repeat return the existing line. Permissions are checked by the caller.
     * See docs/13-folio/01-folios-and-lines.md.
     */
    public function __invoke(User $actor, Folio $folio, string $amount, string $description, string $idempotencyKey, ?string $serviceDate = null): PostingResult
    {
        return DB::transaction(function () use ($actor, $folio, $amount, $description, $idempotencyKey, $serviceDate): PostingResult {
            $locked = Folio::query()->lockForUpdate()->findOrFail($folio->id);
            $existing = FolioLine::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->folio_id === $locked->id ? PostingResult::replayed([$existing]) : PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $clean = str_replace([',', ' '], '', trim($amount));

            if (preg_match('/^-?\d+(\.\d{1,2})?$/', $clean) !== 1 || BigDecimal::of($clean)->isZero()) {
                return PostingResult::refused(PostingResult::REFUSAL_AMOUNT_INVALID);
            }

            $code = TransactionCode::query()
                ->where('property_id', $locked->property_id)
                ->where('system_key', SystemTransactionCode::Adjustment->value)
                ->first();

            if ($code === null) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($locked->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($locked));
            }

            $value = (string) BigDecimal::of($clean)->toScale(2);
            $line = new FolioLine([
                'transaction_code_id' => $code->id,
                'kind' => $code->kind,
                'description' => trim($description) === '' ? $code->name : trim($description),
                'quantity' => 1,
                'unit_amount' => $value,
                'amount' => $value,
            ]);
            $line->folio_id = $locked->id;
            $line->posting_date = $businessDate->toDateString();
            $line->service_date = $serviceDate ?? $businessDate->toDateString();
            $line->idempotency_key = $idempotencyKey;
            $line->user_id = $actor->id;
            $line->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('folio.adjustment_posted')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'folio_id' => $locked->id,
                    'folio_line_id' => $line->id,
                    'amount' => $value,
                    'posting_date' => $businessDate->toDateString(),
                ])
                ->log('Adjustment posted to folio');

            return PostingResult::posted([$line->refresh()]);
        });
    }
}
