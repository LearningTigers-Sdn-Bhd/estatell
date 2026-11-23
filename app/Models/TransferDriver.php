<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransferDriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver picked for a transfer on the run sheet: own staff, or a subcontractor's driver when `subcontractor` names
 * the company. Never deleted; turned off instead.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $subcontractor
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'phone', 'subcontractor', 'active'])]
class TransferDriver extends Model
{
    /** @use HasFactory<TransferDriverFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
