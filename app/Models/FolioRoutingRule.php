<?php

namespace App\Models;

use App\Enums\RoutingRuleSource;
use App\Enums\TransactionCategory;
use Carbon\CarbonImmutable;
use Database\Factories\FolioRoutingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sends the charges that match it to another folio of the same reservation. A null selector matches all. The table
 * has no `property_id`: the reservation scopes it. See docs/13-folio/03-routing.md.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $reservation_unit_id
 * @property TransactionCategory|null $category
 * @property int|null $transaction_code_id
 * @property int $target_folio_id
 * @property RoutingRuleSource $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['reservation_id', 'reservation_unit_id', 'category', 'transaction_code_id', 'target_folio_id', 'source'])]
class FolioRoutingRule extends Model
{
    /** @use HasFactory<FolioRoutingRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TransactionCategory::class,
            'source' => RoutingRuleSource::class,
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class);
    }

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function targetFolio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'target_folio_id');
    }
}
