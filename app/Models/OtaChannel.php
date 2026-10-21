<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OtaChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An online travel agency (OTA) that owners can hand over logins for. Platform admins manage the list. The
 * Other row is locked: it cannot be renamed or deactivated.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $channex_code
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_other
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'channex_code', 'sort_order', 'is_active', 'is_other'])]
class OtaChannel extends Model
{
    /** @use HasFactory<OtaChannelFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_other' => 'boolean',
        ];
    }

    /**
     * Only the channels that owners can pick.
     *
     * @param  Builder<OtaChannel>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The admin list order: sort order first, then id.
     *
     * @param  Builder<OtaChannel>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
