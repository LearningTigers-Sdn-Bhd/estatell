<?php

namespace App\Actions\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use App\Services\Catalogues\RoomRevenueTaxIds;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveRoomRevenuePolicy
{
    public function __construct(private RoomRevenueTaxIds $roomRevenueTaxIds) {}

    /**
     * Save one policy row and the taxes of its code. Fields the charge mode does not use are cleared, and the free
     * window is kept for cancellations only. A policy with its own taxes links the chosen taxes to its code. A policy
     * that follows the Taxes tab links the Taxes tab selection. The caller validates that `applies_to` is allowed for
     * the code and that the tax ids belong to the property.
     *
     * @param  array<string, mixed>  $attributes  Validated `charge_mode`, `amount`, `percent`, `percent_basis`, `applies_to`, `free_until_days`, `own_taxes`, `tax_ids`, `text_en` and `text_ms`.
     */
    public function __invoke(Property $property, User $actor, SystemTransactionCode $code, array $attributes): RoomRevenuePolicy
    {
        return DB::transaction(function () use ($property, $actor, $code, $attributes): RoomRevenuePolicy {
            $policy = $property->roomRevenuePolicies()->where('code', $code->value)->lockForUpdate()->firstOrFail();

            $mode = PolicyChargeMode::from($attributes['charge_mode']);
            $ownTaxes = (bool) ($attributes['own_taxes'] ?? false);

            $policy->fill([
                ...Arr::except($attributes, ['tax_ids']),
                'own_taxes' => $ownTaxes,
                'amount' => $mode === PolicyChargeMode::FixedAmount ? $attributes['amount'] : null,
                'percent' => $mode === PolicyChargeMode::Percentage ? $attributes['percent'] : null,
                'percent_basis' => $mode === PolicyChargeMode::Percentage ? $attributes['percent_basis'] : null,
                'free_until_days' => $code === SystemTransactionCode::CancellationRevenue ? ($attributes['free_until_days'] ?? null) : null,
            ]);
            $changes = array_keys($policy->getDirty());

            $taxIds = $ownTaxes
                ? $this->sorted($attributes['tax_ids'] ?? [])
                : $this->roomRevenueTaxIds->forRoom($property);
            $taxesChanged = $this->roomRevenueTaxIds->linkedTo($property, $code) !== $taxIds;

            if ($changes === [] && ! $taxesChanged) {
                return $policy;
            }

            $policy->save();

            if ($taxesChanged) {
                $property->transactionCodes()->where('system_key', $code->value)->firstOrFail()->applicableTaxes()->sync($taxIds);
                $changes[] = 'tax_ids';
            }

            activity()->performedOn($policy)->causedBy($actor)->event('room_revenue_policy.updated')
                ->withProperties([
                    'property_id' => $property->id,
                    'code' => $code->value,
                    'changed' => $changes,
                    'own_taxes' => $ownTaxes,
                    'tax_ids' => $taxIds,
                ])->log('Room revenue policy updated');

            return $policy;
        });
    }

    /**
     * @param  array<int, mixed>  $taxIds
     * @return list<int>
     */
    private function sorted(array $taxIds): array
    {
        $ids = array_map('intval', array_values($taxIds));
        sort($ids);

        return $ids;
    }
}
