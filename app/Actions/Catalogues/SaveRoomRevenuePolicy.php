<?php

namespace App\Actions\Catalogues;

use App\Enums\PolicyChargeMode;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use App\Models\User;
use App\Services\Catalogues\RoomRevenueTaxIds;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveRoomRevenuePolicy
{
    public function __construct(private RoomRevenueTaxIds $roomRevenueTaxIds) {}

    /**
     * Save one policy row, its cancellation steps and the taxes of its code. Fields the charge mode does not use are
     * cleared. A paid cancellation keeps its charge in steps, so its own `amount` and `percent` stay empty. A policy with its own taxes links the chosen taxes to its code. A policy
     * that follows the Taxes tab links the Taxes tab selection. The caller validates that `applies_to` is allowed for
     * the code and that the tax ids belong to the property.
     *
     * @param  array<string, mixed>  $attributes  Validated `charge_mode`, `amount`, `percent`, `percent_basis`, `applies_to`, `steps` (cancellation only), `own_taxes`, `tax_ids`, `text_en` and `text_ms`.
     */
    public function __invoke(Property $property, User $actor, SystemTransactionCode $code, array $attributes): RoomRevenuePolicy
    {
        return DB::transaction(function () use ($property, $actor, $code, $attributes): RoomRevenuePolicy {
            $policy = $property->roomRevenuePolicies()->where('code', $code->value)->lockForUpdate()->firstOrFail();

            $mode = PolicyChargeMode::from($attributes['charge_mode']);
            $ownTaxes = (bool) ($attributes['own_taxes'] ?? false);
            $isCancellation = $code === SystemTransactionCode::CancellationRevenue;

            $policy->fill([
                ...Arr::except($attributes, ['tax_ids', 'steps']),
                'own_taxes' => $ownTaxes,
                'amount' => $mode === PolicyChargeMode::FixedAmount && ! $isCancellation ? $attributes['amount'] : null,
                'percent' => $mode === PolicyChargeMode::Percentage && ! $isCancellation ? $attributes['percent'] : null,
                'percent_basis' => $mode === PolicyChargeMode::Percentage ? $attributes['percent_basis'] : null,
            ]);
            $changes = array_keys($policy->getDirty());

            $steps = $isCancellation && $mode !== PolicyChargeMode::Free ? $this->steps($attributes['steps'] ?? [], $mode) : [];
            $stepsChanged = $isCancellation && $this->savedSteps($policy) !== $steps;

            $taxIds = $ownTaxes
                ? $this->sorted($attributes['tax_ids'] ?? [])
                : $this->roomRevenueTaxIds->forRoom($property);
            $taxesChanged = $this->roomRevenueTaxIds->linkedTo($property, $code) !== $taxIds;

            if ($changes === [] && ! $taxesChanged && ! $stepsChanged) {
                return $policy;
            }

            $policy->save();

            if ($stepsChanged) {
                $policy->steps()->delete();
                $policy->steps()->createMany(array_map(fn (array $step): array => [...$step, 'property_id' => $property->id], $steps));
                $policy->unsetRelation('steps');
                $changes[] = 'steps';
            }

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
                    'steps' => $isCancellation ? $steps : null,
                ])->log('Room revenue policy updated');

            return $policy;
        });
    }

    /**
     * The typed steps with the charge field of the mode only, most days first, as the database stores them.
     *
     * @param  array<int, array<string, mixed>>  $typed
     * @return list<array{days_before: int, amount: string|null, percent: string|null}>
     */
    private function steps(array $typed, PolicyChargeMode $mode): array
    {
        $steps = array_map(fn (array $step): array => [
            'days_before' => (int) $step['days_before'],
            'amount' => $mode === PolicyChargeMode::FixedAmount ? $this->decimal($step['amount']) : null,
            'percent' => $mode === PolicyChargeMode::Percentage ? $this->decimal($step['percent']) : null,
        ], array_values($typed));
        usort($steps, fn (array $first, array $second): int => $second['days_before'] <=> $first['days_before']);

        return $steps;
    }

    /**
     * @return list<array{days_before: int, amount: string|null, percent: string|null}>
     */
    private function savedSteps(RoomRevenuePolicy $policy): array
    {
        return array_values($policy->steps()->get()->map(fn (RoomRevenuePolicyStep $step): array => [
            'days_before' => $step->days_before,
            'amount' => $step->amount,
            'percent' => $step->percent,
        ])->all());
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2);
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
