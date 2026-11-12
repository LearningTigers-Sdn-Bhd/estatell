<?php

namespace App\Models;

use App\Enums\CommissionService;
use Carbon\CarbonImmutable;
use Database\Factories\CommissionRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform commission rate for one service, from a start date. A change adds a row; rows are never edited.
 * See docs/30-commission-fees/02-commission-rules.md#rates.
 *
 * @property int $id
 * @property CommissionService $service
 * @property string $rate
 * @property CarbonImmutable $effective_from
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['service', 'rate', 'effective_from', 'created_by'])]
class CommissionRate extends Model
{
    /** @use HasFactory<CommissionRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => CommissionService::class,
            'rate' => 'decimal:2',
            'effective_from' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
