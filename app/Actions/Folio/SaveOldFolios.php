<?php

namespace App\Actions\Folio;

use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Data\Folio\OldFolioRowData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\OldFolioRowKind;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\OldFolioLines;
use App\Services\Folio\OldFolioPage;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Payments\AvailablePaymentMethod;
use App\Services\Property\MigrationWindow;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaveOldFolios
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
        private MigrationWindow $migrationWindow,
        private PostFolioCharge $postFolioCharge,
        private RecordPayment $recordPayment,
        private AvailablePaymentMethod $availablePaymentMethod,
        private OldFolioLines $oldFolioLines,
        private OldFolioPage $oldFolioPage,
        private FolioRouter $folioRouter,
        private CreateFolio $createFolio,
        private ReverseLine $reverseLine,
        private ReverseMigratedLines $reverseMigratedLines,
    ) {}

    /**
     * Save the lines of the old folios onto the reservation's Open folios, all tabs in one transaction, first time or
     * later. Each line has `migrated` = true, its own `service_date` (a day before the go-live date), the business date
     * as `posting_date` and the key `migrated:{folio_id}:{row key}`. A Room or Charge row posts as a charge through
     * `PostFolioCharge` with no tax calculation, because the tax is a row of its own. A Tax row posts as a top-level
     * Tax line (kind Tax, no parent) with its tax's code: the only Tax line with no parent. A Payment row goes through
     * `RecordPayment` with the Old system method, the description as the reference and no receipt number.
     *
     * The rows are the whole page: they are compared with the live saved lines (`OldFolioLines`) by row key. A row that
     * matches its line writes nothing. A changed row reverses its line (`reversal_of_id`) and posts a new line with the
     * next key `migrated:{folio_id}:{row key}~{n}`. A line with no row is reversed. A new row posts. A changed or
     * removed Old system payment is refunded to Old system (`RefundPayment`, no money moves) and a changed one is
     * recorded again. A folio id below 0 is `-{unit id}`: the unit's own folio is created here, when a row needs it, so
     * opening the page writes nothing. Order: new lines, reversals, refunds, new payments. `$legacyNumbers` holds the old
     * folio number of each folio by id (blank clears it).
     *
     * Posting on a folio that holds a live opening balance reverses that line in the same transaction (the switch from
     * One amount). Only inside the migration window (`migration_window_closed`). Every folio must be an Open folio of the
     * reservation. A repeat of the same rows writes nothing and returns `replayed`. Every check runs before the first
     * write, so a refusal writes nothing and names the row. Logs `reservation.old_folios_saved` with the counts.
     * Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
     *
     * @param  list<OldFolioRowData>  $rows
     * @param  array<int, string|null>  $legacyNumbers
     */
    public function __invoke(User $actor, Reservation $reservation, array $rows, array $legacyNumbers = []): SaveOldFoliosResult
    {
        try {
            return DB::transaction(fn (): SaveOldFoliosResult => $this->save($actor, $reservation, $rows, $legacyNumbers));
        } catch (SaveOldFoliosRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * @param  list<OldFolioRowData>  $rows
     * @param  array<int, string|null>  $legacyNumbers
     */
    private function save(User $actor, Reservation $reservation, array $rows, array $legacyNumbers): SaveOldFoliosResult
    {
        $property = $reservation->property;
        $property->loadMissing('settings');
        $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

        if (! $this->migrationWindow->isOpen($property)) {
            return SaveOldFoliosResult::refused(PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED);
        }

        $businessDate = $this->currentBusinessDate->date($property);
        $goLive = $this->firstBusinessDate->date($property);

        if ($businessDate === null || $goLive === null) {
            return SaveOldFoliosResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
        }

        if (count($rows) > SaveOldFoliosResult::MAX_ROWS) {
            return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_TOO_MANY_ROWS);
        }

        $rows = array_map(function (OldFolioRowData $row): OldFolioRowData {
            $copy = clone $row;
            $copy->key = strtolower($row->key);

            return $copy;
        }, $rows);

        /** @var Collection<int, Folio> $folios */
        $folios = Folio::query()->where('reservation_id', $reservation->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $pending = $this->oldFolioPage->unitsWithoutFolio($reservation)->keyBy(fn (ReservationUnit $unit): int => -$unit->id);
        $ownFolio = $folios->where('type', FolioType::Unit)->mapWithKeys(fn (Folio $folio): array => [-$folio->reservation_unit_id => $folio->id]);
        $rows = array_map(function (OldFolioRowData $row) use ($ownFolio): OldFolioRowData {
            $row->folioId = $ownFolio->get($row->folioId, $row->folioId);

            return $row;
        }, $rows);
        $legacyNumbers = collect($legacyNumbers)->mapWithKeys(fn (?string $number, int $folioId): array => [$ownFolio->get($folioId, $folioId) => $number])->all();
        $live = $this->oldFolioLines->live($property->id, $folios->where('status', FolioStatus::Open)->keys()->all());

        if ($rows === [] && $live === []) {
            return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_NOTHING_TO_SAVE);
        }

        foreach (array_unique(array_map(fn (OldFolioRowData $row): int => $row->folioId, $rows)) as $folioId) {
            if ($folioId < 0) {
                if (! $pending->has($folioId)) {
                    return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_FOLIO_NOT_FOUND);
                }

                continue;
            }

            $folio = $folios->get($folioId);

            if ($folio === null) {
                return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_FOLIO_NOT_FOUND);
            }

            if ($folio->status !== FolioStatus::Open) {
                return SaveOldFoliosResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }
        }

        $currency = $property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to copy old folios.');
        $changes = [];
        $submitted = [];

        foreach ($rows as $row) {
            $submitted[$row->key] = true;
            $entry = $live[$row->key] ?? null;

            if ($entry !== null && $this->isUnchanged($row, $entry, null)) {
                continue;
            }

            $item = $this->prepare($actor, $reservation, $property->id, $row, $goLive);

            if ($item instanceof SaveOldFoliosResult) {
                return $item;
            }

            if ($entry !== null && $this->isUnchanged($row, $entry, $item)) {
                continue;
            }

            $changes[] = ['item' => $item, 'old' => $entry];
        }

        $replaced = array_values(array_filter(array_map(fn (array $change): ?array => $change['old'], $changes)));
        $removed = array_values(array_filter($live, fn (array $entry, string $key): bool => ! isset($submitted[$key]), ARRAY_FILTER_USE_BOTH));
        $numbers = $this->changedNumbers($legacyNumbers, $folios);

        if ($changes === [] && $removed === [] && $numbers === []) {
            return SaveOldFoliosResult::replayed();
        }

        $postingDate = $businessDate->toDateString();
        $versions = $this->oldFolioLines->versions($folios->keys()->all());
        $created = $this->createFolios($actor, $reservation, $changes, $pending, $folios);

        foreach ($legacyNumbers as $folioId => $number) {
            $folio = $folioId < 0 ? ($created[$folioId] ?? null) : $folios->get($folioId);

            if ($folio !== null && $folio->status === FolioStatus::Open) {
                $trimmed = trim((string) $number);
                $folio->forceFill(['legacy_number' => $trimmed === '' ? null : $trimmed])->save();
            }
        }

        $openingReversed = $this->reverseOpeningBalances($actor, $property->id, $changes, $folios, $created, $postingDate);
        $counts = ['room' => 0, 'charge' => 0, 'tax' => 0, 'payment' => 0];
        $keys = [];

        foreach ($changes as $index => $change) {
            $row = $change['item']['row'];
            $folio = $row->folioId < 0 ? $created[$row->folioId] : $folios->get($row->folioId);
            $keys[$index] = ['folio' => $folio, 'key' => OldFolioLines::lineKey($folio->id, $row->key, $versions[$row->key] ?? 0)];
        }

        foreach ($changes as $index => $change) {
            $row = $change['item']['row'];

            if ($row->kind === OldFolioRowKind::Payment) {
                continue;
            }

            $counts[$row->kind->value]++;
            $folio = $keys[$index]['folio'];

            match ($row->kind) {
                OldFolioRowKind::Tax => $this->writeTax($actor, $folio, $row, $change['item']['tax'], $postingDate, $keys[$index]['key']),
                default => $this->writeCharge($actor, $folio, $row, $change['item'], $currency, $postingDate, $keys[$index]['key']),
            };
        }

        foreach ([...$replaced, ...$removed] as $entry) {
            if ($entry['payment'] === null) {
                $this->reverseMigratedLines->one($actor, $entry['line'], null, $postingDate, $entry['row']->key);
            }
        }

        foreach ([...$replaced, ...$removed] as $entry) {
            if ($entry['payment'] !== null) {
                $this->reverseMigratedLines->one($actor, $entry['line'], $entry['payment'], $postingDate, $entry['row']->key);
            }
        }

        foreach ($changes as $index => $change) {
            $row = $change['item']['row'];

            if ($row->kind === OldFolioRowKind::Payment) {
                $counts['payment']++;
                $this->recordPayment($actor, $reservation, $keys[$index]['folio'], $row, $change['item']['method'], $keys[$index]['key']);
            }
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('reservation.old_folios_saved')
            ->withProperties([
                'property_id' => $reservation->property_id,
                'folio_ids' => array_values(array_unique(array_map(fn (array $key): int => $key['folio']->id, $keys))),
                'lines' => count($changes),
                'room_nights' => $counts['room'],
                'charges' => $counts['charge'],
                'taxes' => $counts['tax'],
                'payments' => $counts['payment'],
                'changed' => count($replaced),
                'removed' => count($removed),
                'opening_balances_reversed' => $openingReversed,
                'posting_date' => $postingDate,
                'go_live_date' => $goLive->toDateString(),
            ])
            ->log('Old folios saved');

        return SaveOldFoliosResult::saved(count($changes), count($replaced), count($removed));
    }

    /**
     * Whether a row still matches the live line it came from. Without a checked item (`$item` null) the row must
     * match on what it holds itself: the picked item and a written description. With one, the description may be
     * the item's default and the line's transaction code is compared.
     *
     * @param  array{line: FolioLine, payment: Payment|null, row: OldFolioRowData}  $entry
     * @param  array{row: OldFolioRowData, code: TransactionCode|null, name: string|null, unit: ReservationUnit|null, tax: Tax|null, method: PaymentMethod|null}|null  $item
     */
    private function isUnchanged(OldFolioRowData $row, array $entry, ?array $item): bool
    {
        $saved = $entry['row'];

        if ($row->kind !== $saved->kind || $row->folioId !== $saved->folioId || $row->serviceDate !== $saved->serviceDate || $row->unitId !== $saved->unitId || $row->quantity !== $saved->quantity) {
            return false;
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $row->amount) !== 1 || ! BigDecimal::of($row->amount)->isEqualTo($saved->amount)) {
            return false;
        }

        $written = trim((string) $row->description);

        if ($item === null) {
            $same = match ($row->kind) {
                OldFolioRowKind::Room => true,
                OldFolioRowKind::Charge => $row->chargeItemId !== null && $row->chargeItemId === $saved->chargeItemId,
                OldFolioRowKind::Tax => $row->taxId !== null && $row->taxId === $saved->taxId,
                OldFolioRowKind::Payment => $row->paymentMethodId !== null && $row->paymentMethodId === $saved->paymentMethodId,
            };

            return $same && $written !== '' && $written === trim((string) $saved->description);
        }

        if ($row->kind === OldFolioRowKind::Payment) {
            return $item['method']?->id === $saved->paymentMethodId && $written === trim((string) $saved->description);
        }

        $description = $written !== '' ? $written : (string) $item['name'];

        return $item['code']?->id === $entry['line']->transaction_code_id && $description === $entry['line']->description;
    }

    /**
     * The folios whose old folio number changes: the Open folios in `$legacyNumbers` with another number.
     *
     * @param  array<int, string|null>  $legacyNumbers
     * @param  Collection<int, Folio>  $folios
     * @return list<int>
     */
    private function changedNumbers(array $legacyNumbers, Collection $folios): array
    {
        $changed = [];

        foreach ($legacyNumbers as $folioId => $number) {
            $folio = $folios->get($folioId);

            if ($folio !== null && $folio->status === FolioStatus::Open && ($folio->legacy_number ?? '') !== trim((string) $number)) {
                $changed[] = $folioId;
            }
        }

        return $changed;
    }

    /**
     * Create the unit folio of each pending unit (a folio id of `-{unit id}`) that a changed row posts on.
     *
     * @param  list<array{item: array<string, mixed>, old: array<string, mixed>|null}>  $changes
     * @param  Collection<int, ReservationUnit>  $pending  By the negative folio id
     * @param  Collection<int, Folio>  $folios
     * @return array<int, Folio> By the negative folio id
     */
    private function createFolios(User $actor, Reservation $reservation, array $changes, Collection $pending, Collection $folios): array
    {
        $created = [];

        foreach ($changes as $change) {
            $folioId = $change['item']['row']->folioId;

            if ($folioId < 0 && ! isset($created[$folioId])) {
                $unit = $pending->get($folioId);
                $created[$folioId] = $this->folioRouter->unitFolio($unit) ?? ($this->createFolio)($reservation, $unit);
                $folios->put($created[$folioId]->id, $created[$folioId]);
            }
        }

        return $created;
    }

    /**
     * Reverse the live opening balance of each folio that receives a line, so a folio carries One amount or Copy line by
     * line, never both. Returns the number of lines reversed.
     *
     * @param  list<array{item: array<string, mixed>, old: array<string, mixed>|null}>  $changes
     * @param  Collection<int, Folio>  $folios
     * @param  array<int, Folio>  $created
     */
    private function reverseOpeningBalances(User $actor, int $propertyId, array $changes, Collection $folios, array $created, string $postingDate): int
    {
        $code = PostOpeningBalance::code($propertyId);
        $reversed = 0;

        if ($code === null) {
            return 0;
        }

        $ids = [];

        foreach ($changes as $change) {
            $folioId = $change['item']['row']->folioId;
            $ids[] = $folioId < 0 ? $created[$folioId]->id : $folioId;
        }

        foreach (array_unique($ids) as $id) {
            $folio = $folios->get($id);

            while ($folio !== null && ($line = PostOpeningBalance::liveLine($folio, $code)) !== null) {
                $this->reverseLine->post($actor, $line, $postingDate, null);
                $reversed++;
            }
        }

        return $reversed;
    }

    /**
     * Check one row and find what it posts with, or return the refusal that names it.
     *
     * @return array{row: OldFolioRowData, code: TransactionCode|null, name: string|null, unit: ReservationUnit|null, tax: Tax|null, method: PaymentMethod|null}|SaveOldFoliosResult
     */
    private function prepare(User $actor, Reservation $reservation, int $propertyId, OldFolioRowData $row, CarbonImmutable $goLive): array|SaveOldFoliosResult
    {
        $refuse = fn (string $code): SaveOldFoliosResult => SaveOldFoliosResult::refused($code, $row->key);
        $item = ['row' => $row, 'code' => null, 'name' => null, 'unit' => null, 'tax' => null, 'method' => null];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $row->serviceDate) !== 1 || CarbonImmutable::createFromFormat('Y-m-d', $row->serviceDate)?->toDateString() !== $row->serviceDate) {
            return $refuse(PostingResult::REFUSAL_SERVICE_DATE_INVALID);
        }

        if ($row->serviceDate >= $goLive->toDateString()) {
            return $refuse(SaveOldFoliosResult::REFUSAL_DATE_NOT_BEFORE_GO_LIVE);
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $row->amount) !== 1 || ! BigDecimal::of($row->amount)->isPositive()) {
            return $refuse(PostingResult::REFUSAL_AMOUNT_INVALID);
        }

        if ($row->quantity < 1 || $row->quantity > 999 || ($row->kind === OldFolioRowKind::Tax || $row->kind === OldFolioRowKind::Payment) && $row->quantity !== 1) {
            return $refuse(PostingResult::REFUSAL_QUANTITY_INVALID);
        }

        if ($row->kind === OldFolioRowKind::Room) {
            $unit = ReservationUnit::query()->where('reservation_id', $reservation->id)->with('roomType:id,name')->find($row->unitId);
            $code = TransactionCode::query()->where('property_id', $propertyId)->where('system_key', SystemTransactionCode::RoomRevenue->value)->first();

            if ($unit === null) {
                return $refuse(SaveOldFoliosResult::REFUSAL_UNIT_NOT_FOUND);
            }

            if ($code === null) {
                return $refuse(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            return [...$item, 'code' => $code, 'unit' => $unit, 'name' => __('Room · :type', ['type' => $unit->roomType->name])];
        }

        if ($row->kind === OldFolioRowKind::Charge) {
            $chargeItem = $row->chargeItemId === null ? null : ChargeItem::query()->where('property_id', $propertyId)->active()->with('transactionCode')->find($row->chargeItemId);

            if ($chargeItem === null || $chargeItem->transactionCode->kind !== TransactionKind::Charge) {
                return $refuse($row->chargeItemId === null ? SaveOldFoliosResult::REFUSAL_ITEM_REQUIRED : PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            return [...$item, 'code' => $chargeItem->transactionCode, 'name' => $chargeItem->name];
        }

        if ($row->kind === OldFolioRowKind::Tax) {
            $tax = $row->taxId === null ? null : Tax::query()->where('property_id', $propertyId)->with('transactionCode')->find($row->taxId);

            if ($tax === null) {
                return $refuse($row->taxId === null ? SaveOldFoliosResult::REFUSAL_ITEM_REQUIRED : PostingResult::REFUSAL_TAX_NOT_AVAILABLE);
            }

            return [...$item, 'tax' => $tax, 'code' => $tax->transactionCode, 'name' => $tax->name];
        }

        $method = $row->paymentMethodId === null ? null : $this->availablePaymentMethod->find($reservation, $row->paymentMethodId, $actor);

        if ($method === null || $method->type !== PaymentMethodType::OldSystem) {
            return $refuse($row->paymentMethodId === null ? SaveOldFoliosResult::REFUSAL_ITEM_REQUIRED : PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
        }

        if (! filled($row->description)) {
            return $refuse(SaveOldFoliosResult::REFUSAL_DESCRIPTION_REQUIRED);
        }

        return [...$item, 'method' => $method];
    }

    /**
     * A Room or Charge row: one charge line with no tax line.
     *
     * @param  array{row: OldFolioRowData, code: TransactionCode|null, name: string|null, unit: ReservationUnit|null, tax: Tax|null, method: PaymentMethod|null}  $item
     */
    private function writeCharge(User $actor, Folio $folio, OldFolioRowData $row, array $item, string $currency, string $postingDate, string $key): void
    {
        $unitAmount = Money::of($row->amount, $currency);
        $entered = $unitAmount->multipliedBy($row->quantity);

        ($this->postFolioCharge)(
            $actor,
            $folio,
            $item['code'],
            filled($row->description) ? trim((string) $row->description) : (string) $item['name'],
            $row->quantity,
            $unitAmount,
            TaxCalculationResult::calculated($entered, [], $entered),
            $key,
            $postingDate,
            $item['unit'],
            serviceDate: $row->serviceDate,
            migrated: true,
        );
    }

    /**
     * A Tax row: a top-level Tax line (no parent) with its tax's code.
     */
    private function writeTax(User $actor, Folio $folio, OldFolioRowData $row, Tax $tax, string $postingDate, string $key): void
    {
        $line = new FolioLine([
            'transaction_code_id' => $tax->transaction_code_id,
            'kind' => TransactionKind::Tax,
            'description' => filled($row->description) ? trim((string) $row->description) : $tax->name,
            'quantity' => 1,
            'unit_amount' => $row->amount,
            'amount' => $row->amount,
        ]);
        $line->folio_id = $folio->id;
        $line->posting_date = $postingDate;
        $line->service_date = $row->serviceDate;
        $line->migrated = true;
        $line->idempotency_key = $key;
        $line->user_id = $actor->id;
        $line->save();
    }

    private function recordPayment(User $actor, Reservation $reservation, Folio $folio, OldFolioRowData $row, PaymentMethod $method, string $key): void
    {
        $result = ($this->recordPayment)($actor, $reservation, $folio, null, new NewPaymentData(
            paymentMethodId: $method->id,
            purpose: PaymentPurpose::Settlement,
            amount: $row->amount,
            reference: trim((string) $row->description),
            idempotencyKey: $row->key,
            serviceDate: $row->serviceDate,
            migrated: true,
            lineKey: $key,
        ));

        if ($result->isRefused()) {
            throw new SaveOldFoliosRefused(SaveOldFoliosResult::refused((string) $result->refusal, $row->key));
        }
    }
}
