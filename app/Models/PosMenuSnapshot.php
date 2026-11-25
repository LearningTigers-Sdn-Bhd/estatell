<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A menu an outlet's register took for offline selling, with the prices it showed. Kept for seven days.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $version
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $issued_at
 */
#[Fillable(['property_id', 'pos_outlet_id', 'version', 'payload', 'issued_at'])]
class PosMenuSnapshot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }
}
