<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdatePaymentMethod;
use App\Actions\Catalogues\UpdateTax;
use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\CreateRoomType;
use App\Data\Catalogues\TaxInputData;
use App\Enums\CompanyAccess;
use App\Enums\RateDerivation;
use App\Enums\SellUnit;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysBundle;
use App\Services\Onboarding\Wastays\WastaysCodeMap;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Writes the setup of a wastays hotel (room types, rooms, rate plans, rates, taxes, charge items, payment methods and
 * the transaction code links) through the existing Actions, in one transaction. Every written row gets an import link,
 * so a second run skips it. See docs/migration-plan/grand-eastern-hotel.md "Setup import".
 */
class ImportWastaysSetup
{
    private const string RATES_UNTIL = '2027-12-31';

    public function __construct(
        private CreateRoomType $createRoomType,
        private CreateRoom $createRoom,
        private UpdateRatePlan $updateRatePlan,
        private SyncRatePlanRoomTypes $syncRatePlanRoomTypes,
        private CreateDerivedRatePlan $createDerivedRatePlan,
        private SetRatePlanCompanyAccess $setRatePlanCompanyAccess,
        private SaveRates $saveRates,
        private UpdateTax $updateTax,
        private UpdatePaymentMethod $updatePaymentMethod,
        private CreateChargeItem $createChargeItem,
        private RecordImportLink $recordImportLink,
        private ImportLinks $links,
        private WastaysCodeMap $map,
    ) {}

    /**
     * @throws RuntimeException with a sentence for the console when the bundle cannot be imported; nothing is written then
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): WastaysSetupSummary
    {
        DB::beginTransaction();

        try {
            $summary = $this->import($target->bundle, $target->property, $target->actor);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $summary;
    }

    private function import(WastaysBundle $bundle, Property $property, User $actor): WastaysSetupSummary
    {
        $summary = new WastaysSetupSummary;

        [$roomTypes, $newRoomTypeIds] = $this->roomTypes($bundle, $property, $actor, $summary);
        $this->rooms($bundle, $property, $actor, $roomTypes, $summary);
        $standard = $this->ratePlans($bundle, $property, $actor, $roomTypes, $summary);
        $this->rates($bundle, $property, $actor, $standard, $roomTypes, $newRoomTypeIds, $summary);
        $this->salesTax($bundle, $property, $actor, $summary);
        $this->skipDbkk($bundle, $summary);

        $codes = $bundle->rows('transaction_codes')->keyBy('id')->all();
        $targets = [];
        $targets += $this->chargeItems($bundle, $property, $actor, $codes, $summary);
        $targets += $this->paymentMethods($bundle, $property, $actor, $codes, $summary);
        $this->transactionCodes($bundle, $property, $codes, $targets, $summary);

        return $summary;
    }

    /**
     * @return array{0: array<string, RoomType>, 1: list<string>} the room types by wastays id, and the ids written in this run
     */
    private function roomTypes(WastaysBundle $bundle, Property $property, User $actor, WastaysSetupSummary $summary): array
    {
        $roomTypes = [];
        $created = [];

        foreach ($bundle->rows('room_types')->sortBy(fn (array $row): int => (int) $row['id']) as $row) {
            $id = (string) $row['id'];
            $linkedId = $this->links->targetId($property, 'room_types', $id);

            if ($linkedId !== null) {
                $roomTypes[$id] = RoomType::query()->findOrFail($linkedId);
                $summary->count('Room types', 'linked');

                continue;
            }

            $name = $this->map->titleCase((string) $row['name']);
            $maxAdults = max(1, (int) $row['max_adults']);

            if ($property->roomTypes()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                throw new RuntimeException("The property already has a room type named {$name}.");
            }

            $roomType = ($this->createRoomType)($property, $actor, [
                'name' => $name,
                'description' => null,
                'sell_unit' => SellUnit::Room->value,
                'max_adults' => $maxAdults,
                'max_children' => (int) ($row['max_children'] ?? 0),
                'base_occupancy' => min(2, $maxAdults),
                'smoking_allowed' => $this->map->isTrue($row['smoking_allowed'] ?? null),
                'pets_allowed' => $this->map->isTrue($row['pets_allowed'] ?? null),
            ]);

            ($this->recordImportLink)($property, 'room_types', $id, $roomType);
            $roomTypes[$id] = $roomType;
            $created[] = $id;
            $summary->count('Room types', 'created');
        }

        return [$roomTypes, $created];
    }

    /** @param  array<string, RoomType>  $roomTypes */
    private function rooms(WastaysBundle $bundle, Property $property, User $actor, array $roomTypes, WastaysSetupSummary $summary): void
    {
        $rows = $bundle->rows('rooms')->sortBy(fn (array $row): array => [(int) ($row['position'] ?? 0), (int) $row['id']]);

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            if ($row['archived_at'] ?? null) {
                $summary->count('Rooms', 'skipped');

                continue;
            }

            if ($this->links->has($property, 'rooms', $id)) {
                $summary->count('Rooms', 'linked');

                continue;
            }

            $roomType = $roomTypes[(string) $row['room_type_id']] ?? throw new RuntimeException('A room points to a room type that is not in the bundle.');
            $number = trim((string) $row['number']);

            if ($property->rooms()->active()->whereRaw('lower(number) = ?', [mb_strtolower($number)])->exists()) {
                throw new RuntimeException("The property already has a room numbered {$number}.");
            }

            $room = ($this->createRoom)($property, $actor, ['number' => $number, 'room_type_id' => $roomType->id, 'room_group_id' => null]);

            ($this->recordImportLink)($property, 'rooms', $id, $room);
            $summary->count('Rooms', 'created');
        }
    }

    /**
     * Rename Basic Rate to Standard Rate, sell every imported room type on it, make Walk-in Rate and Corporate Rate,
     * and link the wastays rate plans by name.
     *
     * @param  array<string, RoomType>  $roomTypes
     */
    private function ratePlans(WastaysBundle $bundle, Property $property, User $actor, array $roomTypes, WastaysSetupSummary $summary): RatePlan
    {
        $standard = $this->activePlan($property, 'Standard Rate') ?? $this->activePlan($property, 'Basic Rate')
            ?? throw new RuntimeException('The property has no Basic Rate plan to rename to Standard Rate.');

        if ($standard->name !== 'Standard Rate' && ! ($this->updateRatePlan)($actor, $standard, ['name' => 'Standard Rate'])) {
            throw new RuntimeException('The Basic Rate plan could not be renamed.');
        }

        $wanted = array_values(array_unique([
            ...$standard->roomTypes()->active()->pluck('room_types.id')->all(),
            ...array_map(fn (RoomType $roomType): int => $roomType->id, array_values($roomTypes)),
        ]));

        if (! ($this->syncRatePlanRoomTypes)($actor, $standard, $wanted)) {
            throw new RuntimeException('Standard Rate could not be set to sell the imported room types.');
        }

        $plans = ['standard' => $standard->refresh()];

        foreach (['walk-in' => 'Walk-in Rate', 'corporate' => 'Corporate Rate'] as $key => $name) {
            $plans[$key] = $this->activePlan($property, $name)
                ?? ($this->createDerivedRatePlan)($actor, $standard, $name, RateDerivation::Percent, '0.00', $wanted)
                ?? throw new RuntimeException("{$name} could not be created.");
        }

        if (($this->setRatePlanCompanyAccess)($actor, $plans['corporate'], CompanyAccess::All) !== null) {
            throw new RuntimeException('Corporate Rate could not be opened to all companies.');
        }

        foreach ($bundle->rows('rate_plans') as $row) {
            $id = (string) $row['id'];
            $key = $this->map->ratePlanKey((string) $row['name']);

            if ($row['archived_at'] ?? null) {
                $summary->count('Rate plans', 'skipped');
            } elseif ($key === null) {
                $summary->skip('Rate plans', 'wastays plan '.$id.' matches none of Standard, Walk-in or Corporate');
            } elseif ($this->links->has($property, 'rate_plans', $id)) {
                $summary->count('Rate plans', 'linked');
            } else {
                ($this->recordImportLink)($property, 'rate_plans', $id, $plans[$key]);
                $summary->count('Rate plans', 'created');
            }
        }

        return $plans['standard'];
    }

    /**
     * Price the new room types on Standard Rate from the first audit date, at base price without SST.
     *
     * @param  array<string, RoomType>  $roomTypes
     * @param  list<string>  $newRoomTypeIds
     */
    private function rates(WastaysBundle $bundle, Property $property, User $actor, RatePlan $standard, array $roomTypes, array $newRoomTypeIds, WastaysSetupSummary $summary): void
    {
        $priced = [];

        foreach ($bundle->rows('room_types') as $row) {
            $id = (string) $row['id'];

            if (! in_array($id, $newRoomTypeIds, true)) {
                $summary->count('Rates', 'linked');
            } elseif (blank($row['base_price'] ?? null)) {
                $summary->skip('Rates', "room type {$id} has no base price");
            } else {
                $amount = $this->map->netPrice((string) $row['base_price']);

                $priced[] = [
                    'room_type_id' => $roomTypes[$id]->id,
                    'days' => array_fill_keys(range(1, 7), ['amount' => $amount, 'occupancy' => []]),
                ];
                $summary->count('Rates', 'created');
            }
        }

        if ($priced !== [] && ($this->saveRates)($actor, $standard, $bundle->manifest->firstAuditDate, self::RATES_UNTIL, $priced) === false) {
            throw new RuntimeException('The rates could not be saved on Standard Rate.');
        }
    }

    private function salesTax(WastaysBundle $bundle, Property $property, User $actor, WastaysSetupSummary $summary): void
    {
        $hotel = $bundle->rows('hotels')->first() ?? [];

        if (! $this->map->isTrue($hotel['sst_enabled'] ?? null)) {
            $summary->skip('SST', 'the hotel has SST off');

            return;
        }

        $tax = $this->tax($property, SystemTransactionCode::SstTax);
        $registration = filled($hotel['sst_registration_number'] ?? null) ? trim((string) $hotel['sst_registration_number']) : $tax->registration_number;
        $alreadyOn = $tax->enabled && $tax->registration_number === $registration;

        ($this->updateTax)($actor, $tax, new TaxInputData(
            name: $tax->name,
            code: $tax->code,
            rateType: TaxRateType::Percent,
            rate: '8.00',
            isInclusive: false,
            foreignGuestsOnly: $tax->foreign_guests_only,
            collectAtCheckIn: $tax->collect_at_check_in,
            registrationNumber: $registration,
            enabled: true,
        ));

        $summary->count('SST', $alreadyOn ? 'linked' : 'created');
    }

    private function skipDbkk(WastaysBundle $bundle, WastaysSetupSummary $summary): void
    {
        foreach ($bundle->rows('hotel_taxes') as $row) {
            $summary->skip('DBKK', 'hotel tax '.($row['name'] ?? $row['id']).' is not imported');
        }
    }

    /**
     * @param  array<string, array<string, string|null>>  $codes  the wastays transaction codes by id
     * @return array<string, Model> the estatell target of each wastays code that an extra charge wrote
     */
    private function chargeItems(WastaysBundle $bundle, Property $property, User $actor, array $codes, WastaysSetupSummary $summary): array
    {
        $targets = [];

        foreach ($bundle->rows('hotel_extra_charges')->sortBy(fn (array $row): array => [(int) ($row['position'] ?? 0), (int) $row['id']]) as $row) {
            $id = (string) $row['id'];
            $codeId = (string) $row['transaction_code_id'];
            $wastaysCode = $codes[$codeId] ?? throw new RuntimeException('An extra charge points to a transaction code that is not in the bundle.');
            $code = (string) $wastaysCode['code'];
            $linkedId = $this->links->targetId($property, 'hotel_extra_charges', $id);

            if ($this->map->tax($code) !== null) {
                $tax = $this->tax($property, $this->map->tax($code));
                $this->linkOnce($property, 'hotel_extra_charges', $id, $tax, $linkedId, 'Charge items', $summary);
                $targets[$codeId] = $tax;

                continue;
            }

            $starter = $this->map->starterCharge($code);
            $new = $this->map->newCharge($code);

            if ($starter === null && $new === null) {
                $summary->skip('Charge items', "wastays code {$code} has no estatell charge item");

                continue;
            }

            if ($linkedId !== null) {
                $chargeItem = ChargeItem::query()->findOrFail($linkedId);
            } elseif ($starter !== null) {
                $chargeItem = $this->transactionCode($property, $starter)->chargeItem ?? throw new RuntimeException("The property has no charge item for {$starter->label()}.");
            } else {
                if ($property->transactionCodes()->where('code', $new['code'])->exists() || $property->chargeItems()->whereRaw('lower(name) = ?', [mb_strtolower($new['name'])])->exists()) {
                    throw new RuntimeException("The property already has a charge item or code named {$new['name']}.");
                }

                $chargeItem = ($this->createChargeItem)($property, $actor, [
                    'name' => $new['name'],
                    'code' => $new['code'],
                    'category' => $new['category']->value,
                    'active' => $this->map->isTrue($wastaysCode['active'] ?? 'true'),
                ]);
            }

            $this->linkOnce($property, 'hotel_extra_charges', $id, $chargeItem, $linkedId, 'Charge items', $summary);
            $targets[$codeId] = $chargeItem;
        }

        return $targets;
    }

    /**
     * @param  array<string, array<string, string|null>>  $codes
     * @return array<string, Model>
     */
    private function paymentMethods(WastaysBundle $bundle, Property $property, User $actor, array $codes, WastaysSetupSummary $summary): array
    {
        $targets = [];

        foreach ($bundle->rows('hotel_payment_methods') as $row) {
            $id = (string) $row['id'];
            $codeId = (string) $row['transaction_code_id'];
            $code = (string) ($codes[$codeId]['code'] ?? throw new RuntimeException('A payment method points to a transaction code that is not in the bundle.'));
            $systemCode = $this->map->payment($code);

            if ($systemCode === null) {
                $summary->skip('Payment methods', "wastays code {$code} has no estatell payment method");

                continue;
            }

            $paymentMethod = $this->paymentMethod($property, $systemCode);

            if (! $paymentMethod->active) {
                ($this->updatePaymentMethod)($actor, $paymentMethod, [
                    'name' => $paymentMethod->name,
                    'surcharge_type' => SurchargeType::None->value,
                    'requires_reference' => $paymentMethod->requires_reference,
                    'active' => true,
                ]);
            }

            $this->linkOnce($property, 'hotel_payment_methods', $id, $paymentMethod, $this->links->targetId($property, 'hotel_payment_methods', $id), 'Payment methods', $summary);
            $targets[$codeId] = $paymentMethod;
        }

        return $targets;
    }

    /**
     * One link per wastays code. A code with folio lines and no target stops the import.
     *
     * @param  array<string, array<string, string|null>>  $codes
     * @param  array<string, Model>  $targets
     */
    private function transactionCodes(WastaysBundle $bundle, Property $property, array $codes, array $targets, WastaysSetupSummary $summary): void
    {
        $lineCounts = $bundle->rows('folio_transactions')->countBy('transaction_code_id')->all();

        foreach ($codes as $codeId => $row) {
            $code = (string) $row['code'];
            $linkedId = $this->links->targetId($property, 'transaction_codes', (string) $codeId);
            $target = $targets[(string) $codeId] ?? $this->targetFor($property, $code);

            if ($target === null && $linkedId === null) {
                $lines = (int) ($lineCounts[$codeId] ?? 0);

                if ($lines > 0) {
                    throw new RuntimeException("The wastays code {$code} has {$lines} folio lines and no estatell target. Nothing was written.");
                }

                $summary->skip('Transaction codes', "wastays code {$code} has no lines");

                continue;
            }

            $this->linkOnce($property, 'transaction_codes', (string) $codeId, $target, $linkedId, 'Transaction codes', $summary);
        }
    }

    /** The payment method, tax or estatell transaction code a wastays code means, when it needs no extra charge row. */
    private function targetFor(Property $property, string $code): ?Model
    {
        return match (true) {
            $this->map->payment($code) !== null => $this->paymentMethod($property, $this->map->payment($code)),
            $this->map->tax($code) !== null => $this->tax($property, $this->map->tax($code)),
            $this->map->starterCharge($code) !== null => $this->transactionCode($property, $this->map->starterCharge($code))->chargeItem,
            $this->map->systemCode($code) !== null => $this->transactionCode($property, $this->map->systemCode($code)),
            default => null,
        };
    }

    private function linkOnce(Property $property, string $table, string $id, ?Model $target, ?int $linkedId, string $part, WastaysSetupSummary $summary): void
    {
        if ($linkedId !== null) {
            $summary->count($part, 'linked');

            return;
        }

        ($this->recordImportLink)($property, $table, $id, $target ?? throw new RuntimeException("No estatell target for {$table} {$id}."));
        $summary->count($part, 'created');
    }

    private function activePlan(Property $property, string $name): ?RatePlan
    {
        return $property->ratePlans()->active()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    private function transactionCode(Property $property, SystemTransactionCode $systemCode): TransactionCode
    {
        return $property->transactionCodes()->where('system_key', $systemCode->value)->firstOrFail();
    }

    private function tax(Property $property, SystemTransactionCode $systemCode): Tax
    {
        return $property->taxes()->where('transaction_code_id', $this->transactionCode($property, $systemCode)->id)->firstOrFail();
    }

    private function paymentMethod(Property $property, SystemTransactionCode $systemCode): PaymentMethod
    {
        return $property->paymentMethods()->where('transaction_code_id', $this->transactionCode($property, $systemCode)->id)->firstOrFail();
    }
}
