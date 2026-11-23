<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UnitTransferAddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a transfer to the charge add-on rows it created. It has no `property_id`: the transfer and the add-on scope it.
 *
 * @property int $id
 * @property int $unit_transfer_id
 * @property int $unit_add_on_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['unit_transfer_id', 'unit_add_on_id'])]
class UnitTransferAddOn extends Model
{
    /** @use HasFactory<UnitTransferAddOnFactory> */
    use HasFactory;

    /** @return BelongsTo<UnitTransfer, $this> */
    public function unitTransfer(): BelongsTo
    {
        return $this->belongsTo(UnitTransfer::class);
    }

    /** @return BelongsTo<UnitAddOn, $this> */
    public function unitAddOn(): BelongsTo
    {
        return $this->belongsTo(UnitAddOn::class);
    }
}
