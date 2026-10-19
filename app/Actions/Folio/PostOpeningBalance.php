<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioPostability;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PostOpeningBalance
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
        private FolioRouter $folioRouter,
        private FolioPostability $folioPostability,
        private CreateFolio $createFolio,
        private ReverseMigratedLines $reverseMigratedLines,
    ) {}

    /**
     * Post what the guest still owes in the old system as one line with the Opening balance code on the unit's own
     * folio (created when missing). `posting_date` is the business date and `service_date` is the go-live date. The
     * description is "Opening balance · old folio {number}", or "Opening balance" without a number, and the number is
     * kept on `folios.legacy_number`. The amount is signed: positive when the guest owes, negative for a credit. Zero
     * posts nothing. There is no tax line. The key is `opening:{folio_id}:{n}`. A folio that already has a live
     * opening line is refused (`opening_balance_exists`): `ChangeOpeningBalance` changes it. The folio row is locked
     * first, and every check runs before the first write. A folio that holds live lines copied from the old system
     * (Copy line by line) has them taken back first, in the same transaction (`ReverseMigratedLines`): a folio carries one
     * amount or copied lines, never both. The migration window is checked by the caller. Permissions
     * (`folio.manual`) are checked by the route. See docs/12-reservations/12-past-and-migrated-stays.md#one-amount.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $amount, ?string $legacyNumber): PostingResult
    {
        try {
            return DB::transaction(fn (): PostingResult => $this->post($actor, $unit, $amount, $legacyNumber));
        } catch (SaveOldFoliosRefused $refused) {
            return PostingResult::refused((string) $refused->result->refusal);
        }
    }

    private function post(User $actor, ReservationUnit $unit, string $amount, ?string $legacyNumber): PostingResult
    {
        $normalized = self::normalize($amount);

        if ($normalized === null) {
            return PostingResult::refused(PostingResult::REFUSAL_OPENING_AMOUNT_INVALID);
        }

        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
        $property = $locked->property;
        $businessDate = $this->currentBusinessDate->date($property);
        $goLive = $this->firstBusinessDate->date($property);

        if ($businessDate === null || $goLive === null) {
            return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
        }

        $code = self::code($locked->property_id);

        if ($code === null) {
            return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
        }

        if (BigDecimal::of($normalized)->isZero()) {
            return PostingResult::posted([]);
        }

        $folio = $this->folioRouter->unitFolio($locked) ?? ($this->createFolio)($locked->reservation, $locked);
        $folio = Folio::query()->lockForUpdate()->findOrFail($folio->id);

        if ($folio->status !== FolioStatus::Open) {
            return PostingResult::refused((string) $this->folioPostability->refusalFor($folio));
        }

        if (self::liveLine($folio, $code) !== null) {
            return PostingResult::refused(PostingResult::REFUSAL_OPENING_BALANCE_EXISTS);
        }

        ($this->reverseMigratedLines)($actor, $folio, $businessDate->toDateString());
        $number = trim((string) $legacyNumber);
        $folio->forceFill(['legacy_number' => $number === '' ? null : $number])->save();
        $line = $this->write($actor, $folio, $code, $normalized, $businessDate->toDateString(), $goLive->toDateString());

        activity()
            ->performedOn($folio->reservation)
            ->causedBy($actor)
            ->event('folio.opening_balance_posted')
            ->withProperties([
                'property_id' => $folio->property_id,
                'folio_id' => $folio->id,
                'folio_line_id' => $line->id,
                'amount' => $line->amount,
                'legacy_number' => $folio->legacy_number,
            ])
            ->log('Opening balance posted');

        return PostingResult::posted([$line]);
    }

    /**
     * Write one opening line on a folio, with no check. `ChangeOpeningBalance` calls it after its own checks and
     * inside its transaction. The key counts the lines the folio has had, so a new line never reuses a key.
     */
    public function write(User $actor, Folio $folio, TransactionCode $code, string $amount, string $postingDate, string $serviceDate): FolioLine
    {
        $count = FolioLine::query()->where('folio_id', $folio->id)->where('idempotency_key', 'like', 'opening:'.$folio->id.':%')->count();
        $line = new FolioLine([
            'transaction_code_id' => $code->id,
            'kind' => $code->kind,
            'description' => self::description($folio->legacy_number),
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
        ]);
        $line->folio_id = $folio->id;
        $line->posting_date = $postingDate;
        $line->service_date = $serviceDate;
        $line->idempotency_key = 'opening:'.$folio->id.':'.($count + 1);
        $line->user_id = $actor->id;
        $line->save();

        return $line->refresh();
    }

    /**
     * A signed amount as a plain decimal with two places, or null when it is not an amount. Commas and spaces are dropped.
     */
    public static function normalize(string $amount): ?string
    {
        $clean = str_replace([',', ' '], '', trim($amount));

        return preg_match('/^-?\d+(\.\d{1,2})?$/', $clean) === 1 ? (string) BigDecimal::of($clean)->toScale(2) : null;
    }

    public static function description(?string $legacyNumber): string
    {
        $number = trim((string) $legacyNumber);

        return $number === '' ? __('Opening balance') : __('Opening balance · old folio :number', ['number' => $number]);
    }

    public static function code(int $propertyId): ?TransactionCode
    {
        return TransactionCode::query()->where('property_id', $propertyId)->where('system_key', SystemTransactionCode::OpeningBalance->value)->first();
    }

    /**
     * The opening line of the folio that has not been reversed, or null.
     */
    public static function liveLine(Folio $folio, TransactionCode $code): ?FolioLine
    {
        return FolioLine::query()
            ->where('folio_id', $folio->id)
            ->where('transaction_code_id', $code->id)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->oldest('id')
            ->first();
    }
}
