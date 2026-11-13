<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioStatement;
use App\Services\Folio\MoveChargesPreview;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MoveFolioCredit
{
    /** Every line of a credit move has a key that starts with this. */
    public const string KEY_PREFIX = 'credit-move:';

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioStatement $folioStatement,
        private MoveChargesPreview $moveChargesPreview,
    ) {}

    /**
     * Move a folio's credit to other folios of the same reservation that owe money. Each target gets one pair of
     * payment lines with the Credit transfer code and its own `transfer_group_id`: `+amount` "Credit moved to {target}"
     * on the source and `-amount` "Credit from {source}" on the target, both with the note in `notes`. No payment row is written: the money came in
     * once, so the Payments report, revenue and e-invoices do not change. Every folio row is locked in id order before the repeat check, and
     * every check runs before the first write, so a refusal writes and logs nothing. The keys are
     * `credit-move:{key}:{target folio id}:out` and `:in`, so a repeat returns the first lines. Permissions are checked
     * by the caller. See docs/13-folio/02-posting-charges.md#move-credit.
     *
     * @param  list<array{folio_id: int, amount: string}>  $moves
     */
    public function __invoke(User $actor, Folio $source, array $moves, ?string $note, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $source, $moves, $note, $key): PostingResult {
            if ($moves === []) {
                return PostingResult::refused(PostingResult::REFUSAL_CREDIT_MOVE_EMPTY);
            }

            $targetIds = array_map(fn (array $move): int => $move['folio_id'], $moves);
            // Lock first, so a second copy of the same request waits here and then finds the first one's lines.
            $folios = Folio::query()->whereIn('id', [$source->id, ...$targetIds])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = FolioLine::query()->where('idempotency_key', 'like', self::KEY_PREFIX.$key.':%')->orderBy('id')->get();

            if ($existing->isNotEmpty()) {
                return $existing->every(fn (FolioLine $line): bool => in_array($line->folio_id, [$source->id, ...$targetIds], true))
                    ? PostingResult::replayed(array_values($existing->all()))
                    : PostingResult::refused(PostingResult::REFUSAL_TRANSFER_INVALID);
            }

            if (in_array($source->id, $targetIds, true) || count(array_unique($targetIds)) !== count($targetIds)) {
                return PostingResult::refused(PostingResult::REFUSAL_TRANSFER_INVALID);
            }

            $locked = $folios->get($source->id);

            if ($locked === null || $folios->count() !== count($targetIds) + 1 || $folios->contains(fn (Folio $folio): bool => $folio->reservation_id !== $locked->reservation_id)) {
                return PostingResult::refused(PostingResult::REFUSAL_DIFFERENT_RESERVATION);
            }

            if ($folios->contains(fn (Folio $folio): bool => $folio->status !== FolioStatus::Open)) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $amounts = [];

            foreach ($moves as $move) {
                $clean = str_replace([',', ' '], '', trim($move['amount']));

                if (preg_match('/^\d+(\.\d{1,2})?$/', $clean) !== 1 || BigDecimal::of($clean)->isZero()) {
                    return PostingResult::refused(PostingResult::REFUSAL_CREDIT_MOVE_AMOUNT_INVALID);
                }

                $amounts[$move['folio_id']] = BigDecimal::of($clean)->toScale(2);
            }

            $balances = FolioLine::query()->whereIn('folio_id', $folios->modelKeys())->groupBy('folio_id')->selectRaw('folio_id, SUM(amount) AS balance')->pluck('balance', 'folio_id');
            $balance = fn (int $folioId): BigDecimal => BigDecimal::of((string) ($balances[$folioId] ?? '0'));
            $total = array_reduce($amounts, fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());

            if ($total->isGreaterThan($balance($locked->id)->negated())) {
                return PostingResult::refused(PostingResult::REFUSAL_CREDIT_MOVE_ABOVE_CREDIT);
            }

            foreach ($amounts as $folioId => $amount) {
                if ($amount->isGreaterThan($balance($folioId))) {
                    return PostingResult::refused(PostingResult::REFUSAL_CREDIT_MOVE_ABOVE_OWED);
                }
            }

            $code = TransactionCode::query()->where('property_id', $locked->property_id)->where('system_key', SystemTransactionCode::FolioTransfer->value)->first();

            if ($code === null) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $labels = $this->moveChargesPreview->unitLabels($locked->reservation);
            $sourceName = $this->folioStatement->name($locked, $labels);
            $date = $businessDate->toDateString();
            $lines = [];

            foreach ($amounts as $folioId => $amount) {
                $target = $folios->get($folioId);
                $group = (string) Str::uuid();
                $prefix = self::KEY_PREFIX.$key.':'.$folioId;
                $lines[] = $this->line($actor, $locked, $code, __('Credit moved to :folio', ['folio' => $this->folioStatement->name($target, $labels)]), (string) $amount, $date, $group, $prefix.':out', $note);
                $lines[] = $this->line($actor, $target, $code, __('Credit from :folio', ['folio' => $sourceName]), (string) $amount->negated(), $date, $group, $prefix.':in', $note);
            }

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('folio.credit_moved')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'folio_id' => $locked->id,
                    'moves' => array_map(fn (int $folioId, BigDecimal $amount): array => ['folio_id' => $folioId, 'amount' => (string) $amount], array_keys($amounts), array_values($amounts)),
                    'amount' => (string) $total->toScale(2),
                    'note' => filled($note) ? $note : null,
                    'posting_date' => $date,
                ])
                ->log('Folio credit moved');

            return PostingResult::posted($lines);
        });
    }

    private function line(User $actor, Folio $folio, TransactionCode $code, string $description, string $amount, string $date, string $group, string $key, ?string $note): FolioLine
    {
        $line = new FolioLine([
            'transaction_code_id' => $code->id,
            'kind' => TransactionKind::Payment,
            'description' => $description,
            'notes' => filled($note) ? $note : null,
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
        ]);
        $line->folio_id = $folio->id;
        $line->posting_date = $date;
        $line->service_date = $date;
        $line->transfer_group_id = $group;
        $line->idempotency_key = $key;
        $line->user_id = $actor->id;
        $line->save();

        return $line;
    }
}
