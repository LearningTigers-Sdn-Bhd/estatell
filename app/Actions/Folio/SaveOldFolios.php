<?php

namespace App\Actions\Folio;

use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Data\Folio\OldFolioRowData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioStatus;
use App\Enums\OldFolioRowKind;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\TaxCalculationResult;
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
    ) {}

    /**
     * Copy the lines of the old folios onto the reservation's Open folios, all tabs in one transaction. Each line has
     * `migrated` = true, its own `service_date` (a day before the go-live date), the business date as `posting_date`
     * and the key `migrated:{folio_id}:{row key}`. A Room or Charge row posts as a charge through `PostFolioCharge`
     * with no tax calculation, because the tax is a row of its own. A Tax row posts as a top-level Tax line (kind Tax,
     * no parent) with its tax's code: the only Tax line with no parent. A Payment row goes through `RecordPayment`
     * with the Old system method, the description as the reference and no receipt number. Charges and taxes post first
     * and payments last. `$legacyNumbers` holds the old folio number of each folio by id (blank clears it).
     *
     * Only inside the migration window (`migration_window_closed`). Every folio must be an Open folio of the reservation.
     * A folio with a live opening balance line is refused (`opening_balance_exists`), and so is a reservation that already has
     * migrated lines (`old_folios_already_saved`): the first save is the only one. A repeat of the same rows
     * returns `replayed` and writes nothing. Every check runs before the first write, so a refusal writes nothing
     * and names the row. Logs `reservation.old_folios_saved` with the counts. Permissions (`folio.manual`) are checked
     * by the route. See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
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

        if ($rows === []) {
            return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_NOTHING_TO_SAVE);
        }

        if (count($rows) > SaveOldFoliosResult::MAX_ROWS) {
            return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_TOO_MANY_ROWS);
        }

        /** @var Collection<int, Folio> $folios */
        $folios = Folio::query()->where('reservation_id', $reservation->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $keys = array_map(fn (OldFolioRowData $row): string => self::lineKey($row), $rows);

        if (FolioLine::query()->whereIn('idempotency_key', $keys)->exists()) {
            return SaveOldFoliosResult::replayed();
        }

        if (FolioLine::query()->whereIn('folio_id', $folios->keys())->where('migrated', true)->exists()) {
            return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_ALREADY_SAVED);
        }

        $openingCode = PostOpeningBalance::code($property->id);

        foreach (array_unique(array_map(fn (OldFolioRowData $row): int => $row->folioId, $rows)) as $folioId) {
            $folio = $folios->get($folioId);

            if ($folio === null) {
                return SaveOldFoliosResult::refused(SaveOldFoliosResult::REFUSAL_FOLIO_NOT_FOUND);
            }

            if ($folio->status !== FolioStatus::Open) {
                return SaveOldFoliosResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            if ($openingCode !== null && PostOpeningBalance::liveLine($folio, $openingCode) !== null) {
                return SaveOldFoliosResult::refused(PostingResult::REFUSAL_OPENING_BALANCE_EXISTS);
            }
        }

        $currency = $property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to copy old folios.');
        $prepared = [];

        foreach ($rows as $row) {
            $result = $this->prepare($actor, $reservation, $property->id, $row, $goLive);

            if ($result instanceof SaveOldFoliosResult) {
                return $result;
            }

            $prepared[] = $result;
        }

        $ordered = [...array_filter($prepared, fn (array $item): bool => $item['row']->kind !== OldFolioRowKind::Payment), ...array_filter($prepared, fn (array $item): bool => $item['row']->kind === OldFolioRowKind::Payment)];
        $counts = ['room' => 0, 'charge' => 0, 'tax' => 0, 'payment' => 0];

        foreach ($legacyNumbers as $folioId => $number) {
            $folio = $folios->get($folioId);

            if ($folio !== null && $folio->status === FolioStatus::Open) {
                $trimmed = trim((string) $number);
                $folio->forceFill(['legacy_number' => $trimmed === '' ? null : $trimmed])->save();
            }
        }

        foreach ($ordered as $item) {
            $row = $item['row'];
            $folio = $folios->get($row->folioId);
            $counts[$row->kind->value]++;

            match ($row->kind) {
                OldFolioRowKind::Payment => $this->recordPayment($actor, $reservation, $folio, $row, $item['method']),
                OldFolioRowKind::Tax => $this->writeTax($actor, $folio, $row, $item['tax'], $businessDate->toDateString()),
                default => $this->writeCharge($actor, $folio, $row, $item, $currency, $businessDate->toDateString()),
            };
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('reservation.old_folios_saved')
            ->withProperties([
                'property_id' => $reservation->property_id,
                'folio_ids' => array_values(array_unique(array_map(fn (OldFolioRowData $row): int => $row->folioId, $rows))),
                'lines' => count($rows),
                'room_nights' => $counts['room'],
                'charges' => $counts['charge'],
                'taxes' => $counts['tax'],
                'payments' => $counts['payment'],
                'posting_date' => $businessDate->toDateString(),
                'go_live_date' => $goLive->toDateString(),
            ])
            ->log('Old folios saved');

        return SaveOldFoliosResult::saved(count($rows));
    }

    /**
     * The line key of a row: `migrated:{folio_id}:{row key}`.
     */
    public static function lineKey(OldFolioRowData $row): string
    {
        return 'migrated:'.$row->folioId.':'.$row->key;
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
    private function writeCharge(User $actor, Folio $folio, OldFolioRowData $row, array $item, string $currency, string $postingDate): void
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
            self::lineKey($row),
            $postingDate,
            $item['unit'],
            serviceDate: $row->serviceDate,
            migrated: true,
        );
    }

    /**
     * A Tax row: a top-level Tax line (no parent) with its tax's code.
     */
    private function writeTax(User $actor, Folio $folio, OldFolioRowData $row, Tax $tax, string $postingDate): void
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
        $line->idempotency_key = self::lineKey($row);
        $line->user_id = $actor->id;
        $line->save();
    }

    private function recordPayment(User $actor, Reservation $reservation, Folio $folio, OldFolioRowData $row, PaymentMethod $method): void
    {
        $result = ($this->recordPayment)($actor, $reservation, $folio, null, new NewPaymentData(
            paymentMethodId: $method->id,
            purpose: PaymentPurpose::Settlement,
            amount: $row->amount,
            reference: trim((string) $row->description),
            idempotencyKey: $row->key,
            serviceDate: $row->serviceDate,
            migrated: true,
            lineKey: self::lineKey($row),
        ));

        if ($result->isRefused()) {
            throw new SaveOldFoliosRefused(SaveOldFoliosResult::refused((string) $result->refusal, $row->key));
        }
    }
}
