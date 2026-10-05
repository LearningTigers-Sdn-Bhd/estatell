<?php

namespace App\Actions\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRoomRevenuePolicy
{
    /**
     * Save one policy row. Fields the charge mode does not use are cleared, and the free window
     * is kept for cancellations only. The caller validates that `applies_to` is allowed for the code.
     *
     * @param  array<string, mixed>  $attributes  Validated `charge_mode`, `amount`, `percent`, `percent_basis`, `applies_to`, `free_until_days`, `text_en` and `text_ms`.
     */
    public function __invoke(Property $property, User $actor, SystemTransactionCode $code, array $attributes): RoomRevenuePolicy
    {
        return DB::transaction(function () use ($property, $actor, $code, $attributes): RoomRevenuePolicy {
            $policy = $property->roomRevenuePolicies()->where('code', $code->value)->lockForUpdate()->firstOrFail();

            $mode = PolicyChargeMode::from($attributes['charge_mode']);

            $policy->fill([
                ...$attributes,
                'amount' => $mode === PolicyChargeMode::FixedAmount ? $attributes['amount'] : null,
                'percent' => $mode === PolicyChargeMode::Percentage ? $attributes['percent'] : null,
                'percent_basis' => $mode === PolicyChargeMode::Percentage ? $attributes['percent_basis'] : null,
                'free_until_days' => $code === SystemTransactionCode::CancellationRevenue ? ($attributes['free_until_days'] ?? null) : null,
            ]);
            $changes = $policy->getDirty();

            if ($changes === []) {
                return $policy;
            }

            $policy->save();

            activity()->performedOn($policy)->causedBy($actor)->event('room_revenue_policy.updated')
                ->withProperties([
                    'property_id' => $property->id,
                    'code' => $code->value,
                    'changed' => array_keys($changes),
                ])->log('Room revenue policy updated');

            return $policy;
        });
    }
}
