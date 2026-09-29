<?php

namespace App\Actions\Catalogues;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateDiscount
{
    /**
     * Add a discount with its own discount code at the end of the property's active list.
     *
     * @param  array<string, mixed>  $attributes  Validated discount fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): Discount
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Discount {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($attributes['type'] !== DiscountType::Fixed->value) {
                $attributes['allow_override'] = $attributes['type'] === DiscountType::Open->value;
            }

            if ($attributes['type'] === DiscountType::Open->value) {
                $attributes['value'] = null;
            }

            $selectedCodeIds = $attributes['scope'] === DiscountScope::Selected->value ? $attributes['transaction_code_ids'] : [];

            $transactionCode = $property->transactionCodes()->create([
                'system_key' => null,
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'kind' => TransactionKind::Adjustment,
                'category' => TransactionCategory::Discount,
                'gl_account_code' => null,
                'is_system' => false,
                'active' => $attributes['active'],
            ]);

            unset($attributes['code'], $attributes['transaction_code_ids']);

            $discount = new Discount($attributes);
            $discount->property_id = $property->id;
            $discount->transaction_code_id = $transactionCode->id;
            $discount->position = ((int) $property->discounts()->active()->max('position')) + 1;
            $discount->save();
            $discount->selectedTransactionCodes()->sync($selectedCodeIds);

            activity()
                ->performedOn($discount)
                ->causedBy($actor)
                ->event('discount.created')
                ->withProperties(['property_id' => $property->id, 'name' => $discount->name, 'code' => $transactionCode->code])
                ->log('Discount created');

            return $discount;
        });
    }
}
