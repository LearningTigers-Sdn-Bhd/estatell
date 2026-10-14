<?php

namespace App\Models;

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row (id 1) of platform-wide settings.
 *
 * @property int $id
 * @property bool $require_go_live_approval
 * @property DormStrategy $default_dorm_strategy
 * @property MediaDisk $media_disk
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['require_go_live_approval', 'default_dorm_strategy', 'media_disk'])]
class PlatformSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'require_go_live_approval' => 'boolean',
            'default_dorm_strategy' => DormStrategy::class,
            'media_disk' => MediaDisk::class,
        ];
    }
}
