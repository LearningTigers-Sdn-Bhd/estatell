<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalogues\CreateDiscount;
use App\Actions\Catalogues\UpdateDiscount;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\DerivedTransactionCode;
use Illuminate\Support\Facades\DB;

class SaveOnboardingDiscounts
{
    public function __construct(
        private CreateDiscount $createDiscount,
        private UpdateDiscount $updateDiscount,
        private DerivedTransactionCode $derivedCode,
    ) {}

    /**
     * Save the Discounts table in one transaction. A row with an id updates that discount through UpdateDiscount, which
     * keeps its code. A row without an id creates a discount through CreateDiscount with a code derived from the name.
     * Discounts are never deleted: a saved discount is turned off. For a Selected scope, `room_charges` adds the room
     * revenue codes to the chosen charge codes.
     *
     * @param  list<array{id: int|null, name: string, type: DiscountType, value: string|null, allow_override: bool, scope: DiscountScope, active: bool, room_charges: bool, transaction_code_ids: list<int>}>  $rows
     */
    public function __invoke(Property $property, User $actor, array $rows): void
    {
        DB::transaction(function () use ($property, $actor, $rows): void {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->discounts()->with('transactionCode')->get()->keyBy('id');
            $roomCodeIds = $this->roomCodeIds($property);

            foreach ($rows as $row) {
                $codeIds = $row['scope'] === DiscountScope::Selected
                    ? array_values(array_unique([...$row['transaction_code_ids'], ...($row['room_charges'] ? $roomCodeIds : [])]))
                    : [];

                $attributes = [
                    'name' => $row['name'],
                    'type' => $row['type']->value,
                    'value' => $row['value'],
                    'allow_override' => $row['allow_override'],
                    'scope' => $row['scope']->value,
                    'active' => $row['active'],
                    'transaction_code_ids' => $codeIds,
                ];

                if ($row['id'] === null) {
                    ($this->createDiscount)($property, $actor, [
                        ...$attributes,
                        'code' => $this->derivedCode->forName($property, $row['name'], 'DISCOUNT'),
                    ]);

                    continue;
                }

                $discount = $saved->findOrFail($row['id']);

                ($this->updateDiscount)($actor, $discount, [
                    ...$attributes,
                    'code' => $discount->transactionCode->code,
                ]);
            }
        });
    }

    /**
     * @return list<int>
     */
    private function roomCodeIds(Property $property): array
    {
        return $property->transactionCodes()
            ->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
