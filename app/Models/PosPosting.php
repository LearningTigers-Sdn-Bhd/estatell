<?php

namespace App\Models;

use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use Carbon\CarbonImmutable;
use Database\Factories\PosPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One POS receipt as Estatell saw it. A charge links to its folio line; a void links to the reversal line. A receipt
 * that could not be posted is kept as PendingReview with the reason, never dropped. `raw_payload` is the provider's
 * original receipt, so staff can see what the guest ordered.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int|null $reservation_unit_id
 * @property int|null $folio_line_id
 * @property string $external_receipt_id
 * @property string $external_receipt_number
 * @property PosPostingType $posting_type
 * @property PosPostingStatus $status
 * @property string $amount
 * @property string|null $failure_reason
 * @property array<string, mixed> $raw_payload
 * @property int|null $resolved_by_user_id
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution_note
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['pos_outlet_id', 'reservation_unit_id', 'folio_line_id', 'external_receipt_id', 'external_receipt_number', 'posting_type', 'status', 'amount', 'failure_reason', 'raw_payload', 'resolved_by_user_id', 'resolved_at', 'resolution_note', 'posted_at'])]
class PosPosting extends Model
{
    /** @use HasFactory<PosPostingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posting_type' => PosPostingType::class,
            'status' => PosPostingStatus::class,
            'raw_payload' => 'array',
            'resolved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
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
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<FolioLine, $this>
     */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
