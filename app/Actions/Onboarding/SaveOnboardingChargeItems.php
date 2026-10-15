<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingChargeItems
{
    private const CODE_LENGTH = 20;

    public function __construct(
        private CreateChargeItem $createChargeItem,
        private UpdateChargeItem $updateChargeItem,
    ) {}

    /**
     * Save the Charge items table in one transaction. A row with an id updates that item through UpdateChargeItem: its
     * name, price, charging unit, On switch and taxes. A starter item keeps its category and code, and an extra keeps
     * its code. A row without an id creates an item through CreateChargeItem with a code derived from the name.
     * Items are never deleted: a saved item is turned off. Staff can change the price is always on without a price.
     *
     * @param  list<array{id: int|null, name: string, category: TransactionCategory|null, default_amount: string|null, charging_unit: ChargingUnit, allow_override: bool, active: bool, tax_ids: list<int>}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->chargeItems()->with('transactionCode')->get()->keyBy('id');

            foreach ($rows as $row) {
                $allowOverride = $row['default_amount'] === null ? true : $row['allow_override'];

                if ($row['id'] === null) {
                    ($this->createChargeItem)($property, $actor, [
                        'name' => $row['name'],
                        'code' => $this->derivedCode($property, $row['name']),
                        'category' => $row['category'],
                        'description' => null,
                        'default_amount' => $row['default_amount'],
                        'charging_unit' => $row['charging_unit'],
                        'allow_override' => $allowOverride,
                        'active' => $row['active'],
                        'tax_ids' => $row['tax_ids'],
                    ]);

                    continue;
                }

                $chargeItem = $saved->findOrFail($row['id']);

                ($this->updateChargeItem)($actor, $chargeItem, [
                    'name' => $row['name'],
                    'code' => $chargeItem->transactionCode->code,
                    'category' => $row['category'] ?? $chargeItem->transactionCode->category,
                    'default_amount' => $row['default_amount'],
                    'charging_unit' => $row['charging_unit'],
                    'allow_override' => $allowOverride,
                    'active' => $row['active'],
                    'tax_ids' => $row['tax_ids'],
                ]);
            }
        });
    }

    /**
     * The code of a new item: the name in capitals with underscores (Extra bed → EXTRA_BED), at most 20 characters,
     * and a number when a transaction code of the property already has it. Settings can edit it later.
     */
    private function derivedCode(Property $property, string $name): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper($name)), '_');
        $base = $base === '' ? 'CHARGE' : $base;

        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : "_{$number}";
            $candidate = trim(substr($base, 0, self::CODE_LENGTH - strlen($suffix)), '_').$suffix;

            $taken = $property->transactionCodes()->whereRaw('lower(code) = ?', [mb_strtolower($candidate)])->exists();

            if (! $taken) {
                return $candidate;
            }
        }
    }
}
