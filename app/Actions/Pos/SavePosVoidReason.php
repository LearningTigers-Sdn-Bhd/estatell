<?php

namespace App\Actions\Pos;

use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or change a void reason: its label (unique per property, ignoring case: `label_taken`), whether it needs a manager,
 * and whether voided stock goes back by default. A reason is switched off, never deleted, because voided lines point at it.
 * A refusal writes nothing.
 */
class SavePosVoidReason
{
    public function __invoke(User $actor, Property $property, string $label, bool $requiresManagerPin = true, bool $defaultReturnStock = false, bool $active = true, int $sortOrder = 0, ?PosVoidReason $reason = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $label, $requiresManagerPin, $defaultReturnStock, $active, $sortOrder, $reason): PosActionResult {
            if ($reason !== null && $reason->property_id !== $property->id) {
                return PosActionResult::refused('void_reason_invalid');
            }

            $label = trim($label);

            if ($label === '') {
                return PosActionResult::refused('label_required');
            }

            $taken = PosVoidReason::query()
                ->where('property_id', $property->id)
                ->whereRaw('lower(label) = ?', [mb_strtolower($label)])
                ->when($reason !== null, fn ($query) => $query->whereKeyNot($reason->id))
                ->exists();

            if ($taken) {
                return PosActionResult::refused('label_taken');
            }

            $created = $reason === null;
            $reason ??= new PosVoidReason;
            $reason->fill(['label' => $label, 'requires_manager_pin' => $requiresManagerPin, 'default_return_stock' => $defaultReturnStock, 'sort_order' => $sortOrder, 'active' => $active]);
            $reason->property_id = $property->id;
            $reason->save();

            activity()->performedOn($reason)->causedBy($actor)->event($created ? 'pos.void_reason_created' : 'pos.void_reason_updated')
                ->withProperties(['property_id' => $property->id, 'label' => $label])
                ->log($created ? 'POS void reason created' : 'POS void reason updated');

            return PosActionResult::saved($reason);
        });
    }
}
