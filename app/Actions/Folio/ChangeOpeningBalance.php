<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Folio\FolioPostability;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ChangeOpeningBalance
{
    public function __construct(
        private MigrationWindow $migrationWindow,
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
        private FolioPostability $folioPostability,
        private ReverseLine $reverseLine,
        private PostOpeningBalance $postOpeningBalance,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Change the opening balance of a folio: reverse the current opening line and post the new one, in one
     * transaction (staff never see the reversal step). The new line has the next key `opening:{folio_id}:{n}` and the
     * same old folio number. A zero amount reverses only. The folio row is locked first, so a second click on the
     * same line is refused (`already_reversed`). Only inside the migration window (`migration_window_closed`), on an
     * Open folio, and only for a live line with the Opening balance code (`not_opening_balance`). Every check runs
     * before the first write, so a refusal writes and logs nothing. Logged on the reservation as
     * `folio.opening_balance_changed`. Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#one-amount.
     */
    public function __invoke(User $actor, FolioLine $line, string $amount, string $reason): PostingResult
    {
        return DB::transaction(function () use ($actor, $line, $amount, $reason): PostingResult {
            $current = FolioLine::query()->findOrFail($line->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);
            $property = $folio->property;

            if (! $this->migrationWindow->isOpen($property)) {
                return PostingResult::refused(PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED);
            }

            $businessDate = $this->currentBusinessDate->date($property);
            $goLive = $this->firstBusinessDate->date($property);
            $code = PostOpeningBalance::code($folio->property_id);

            if ($businessDate === null || $goLive === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($code === null || $current->transaction_code_id !== $code->id || $current->reversal_of_id !== null || $current->parent_line_id !== null) {
                return PostingResult::refused(PostingResult::REFUSAL_NOT_OPENING_BALANCE);
            }

            if ($current->reversal()->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            if (trim($reason) === '') {
                return PostingResult::refused(PostingResult::REFUSAL_REASON_REQUIRED);
            }

            $normalized = PostOpeningBalance::normalize($amount);

            if ($normalized === null) {
                return PostingResult::refused(PostingResult::REFUSAL_OPENING_AMOUNT_INVALID);
            }

            if ($folio->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
            }

            $reversal = $this->reverseLine->post($actor, $current, $businessDate->toDateString(), null);
            $lines = [$reversal[0]];

            if (! BigDecimal::of($normalized)->isZero()) {
                $lines[] = $this->postOpeningBalance->write($actor, $folio, $code, $normalized, $businessDate->toDateString(), $goLive->toDateString());
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.opening_balance_changed')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'old_amount' => $current->amount,
                    'new_amount' => $normalized,
                    'reason' => trim($reason),
                ])
                ->log('Opening balance changed');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
