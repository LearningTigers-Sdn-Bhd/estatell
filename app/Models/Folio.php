<?php

namespace App\Models;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use Carbon\CarbonImmutable;
use Database\Factories\FolioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The bill for a reservation or one of its units. The balance is `SUM(folio_lines.amount)` and is never stored
 * (see `FolioBalance`). See docs/13-folio/01-folios-and-lines.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_id
 * @property int|null $reservation_unit_id
 * @property string $number
 * @property FolioType $type
 * @property FolioStatus $status
 * @property int|null $payer_guest_id
 * @property int|null $payer_company_id The company that pays this folio.
 * @property string|null $invoice_number
 * @property string|null $legacy_number The folio number in the old system, set by the opening balance.
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'reservation_id', 'reservation_unit_id', 'number', 'type', 'status', 'payer_guest_id', 'payer_company_id',
    'invoice_number', 'legacy_number', 'closed_at', 'closed_by',
])]
class Folio extends Model
{
    /** @use HasFactory<FolioFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FolioType::class,
            'status' => FolioStatus::class,
            'closed_at' => 'datetime',
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
     * @return BelongsTo<Guest, $this>
     */
    public function payerGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'payer_guest_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function payerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'payer_company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<FolioLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
