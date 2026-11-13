<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class UndoCreditMove
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ReverseLine $reverseLine,
    ) {}

    /**
     * Undo one credit move from either of its two lines: a reversal line for both, so the credit goes back to the
     * folio it came from. Both folios are locked in id order, and every check runs before the first write, so a
     * refusal writes and logs nothing. A repeat finds the lines reversed and is refused (`already_reversed`).
     * Permissions are checked by the caller. See docs/13-folio/02-posting-charges.md#move-credit.
     */
    public function __invoke(User $actor, FolioLine $line): PostingResult
    {
        return DB::transaction(function () use ($actor, $line): PostingResult {
            $current = FolioLine::query()->with('transactionCode')->findOrFail($line->id);

            if ($current->transactionCode->system_key !== SystemTransactionCode::FolioTransfer->value || $current->transfer_group_id === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NOT_A_CREDIT_MOVE);
            }

            if ($current->reversal_of_id !== null) {
                return PostingResult::refused(PostingResult::REFUSAL_LINE_IS_REVERSAL);
            }

            $pair = FolioLine::query()
                ->where('transfer_group_id', $current->transfer_group_id)
                ->where('transaction_code_id', $current->transaction_code_id)
                ->whereNull('reversal_of_id')
                ->orderBy('id')
                ->get();
            $folios = Folio::query()->whereIn('id', $pair->pluck('folio_id')->unique()->all())->orderBy('id')->lockForUpdate()->get();

            if ($pair->count() !== 2) {
                return PostingResult::refused(PostingResult::REFUSAL_NOT_A_CREDIT_MOVE);
            }

            if (FolioLine::query()->whereIn('reversal_of_id', $pair->modelKeys())->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            if ($folios->contains(fn (Folio $folio): bool => $folio->status !== FolioStatus::Open)) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $folio = $folios->firstOrFail();
            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $date = $businessDate->toDateString();
            $lines = [];

            foreach ($pair as $moved) {
                $lines = [...$lines, ...$this->reverseLine->post($actor, $moved, $date, $current->transfer_group_id)];
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.credit_move_undone')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_line_ids' => $pair->modelKeys(),
                    'amount' => (string) $pair->max('amount'),
                    'posting_date' => $date,
                ])
                ->log('Folio credit move undone');

            return PostingResult::posted($lines);
        });
    }
}
