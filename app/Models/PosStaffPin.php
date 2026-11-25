<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosStaffPinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's POS PIN for quick user switching on a shared terminal. Only the peppered hash is stored.
 *
 * @property int $id
 * @property int $property_id
 * @property int $user_id
 * @property string $pin_hash
 * @property int $pin_length
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pin_hash', 'pin_length', 'active'])]
#[Hidden(['pin_hash'])]
class PosStaffPin extends Model
{
    /** @use HasFactory<PosStaffPinFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'pin_length' => 'integer'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
