<?php

namespace App\Models;

use App\Enums\AccountingProvider;
use App\Enums\AccountingSyncStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AccountingSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The push of one closed business date to the accounting software. A property has at most one row per date, so a
 * date is pushed once. A Failed row can be pushed again.
 *
 * @property int $id
 * @property int $property_id
 * @property int $business_date_id
 * @property AccountingProvider $provider
 * @property AccountingSyncStatus $status
 * @property string|null $external_id
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'business_date_id', 'provider', 'status', 'external_id', 'error', 'attempts', 'pushed_at'])]
class AccountingSync extends Model
{
    /** @use HasFactory<AccountingSyncFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'provider' => AccountingProvider::class,
            'status' => AccountingSyncStatus::class,
            'attempts' => 'integer',
            'pushed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<BusinessDate, $this>
     */
    public function businessDate(): BelongsTo
    {
        return $this->belongsTo(BusinessDate::class);
    }
}
