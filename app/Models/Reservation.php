<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\CollectedTaxesPayer;
use App\Enums\FolioStatus;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One booking of a property: one or more units (a room or a bed each). A group is a reservation with `group_name` set.
 * `status` is derived from the units by `SyncReservationStatus`. See docs/12-reservations/01-reservation-shape.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $reference
 * @property string $short_code The code a guest types to find their booking. Unique per property, and it never changes.
 * @property ReservationStatus $status
 * @property int $booker_guest_id
 * @property int|null $company_id The billed company.
 * @property string|null $group_name
 * @property BillingMode $billing_mode
 * @property CollectedTaxesPayer|null $collected_taxes_payer Who pays the taxes collected at check-in on this booking. Null follows the company.
 * @property int $booking_source_id
 * @property string|null $external_reference
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $cancellation_requested_at
 * @property int|null $deposit_percent The agent payment schedule, set at booking (`SetReservationPaymentSchedule`): the deposit share.
 * @property CarbonImmutable|null $deposit_due_at When the deposit (or the whole booking, with no full payment date) is due.
 * @property CarbonImmutable|null $full_payment_due_at When the whole booking is due. Null with one stage.
 * @property int|null $cancellation_requested_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'booker_guest_id', 'company_id', 'group_name', 'billing_mode', 'collected_taxes_payer', 'external_reference', 'notes',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'billing_mode' => BillingMode::class,
            'collected_taxes_payer' => CollectedTaxesPayer::class,
            'cancellation_requested_at' => 'datetime',
            'deposit_percent' => 'integer',
            'deposit_due_at' => 'datetime',
            'full_payment_due_at' => 'datetime',
        ];
    }

    /**
     * The bookings whose agent payments are still followed up: Confirmed and In-house, and Completed while a folio the
     * company pays is still Open, because the guests can leave before the agent has paid.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAgentPaymentsOpen(Builder $query): Builder
    {
        return $query->where(fn (Builder $bookings): Builder => $bookings
            ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::InHouse->value])
            ->orWhere(fn (Builder $stayEnded): Builder => $stayEnded
                ->where('status', ReservationStatus::Completed->value)
                ->whereHas('folios', fn (Builder $folios): Builder => $folios->where('status', FolioStatus::Open->value)->whereNotNull('payer_company_id'))));
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<BookingSource, $this>
     */
    public function bookingSource(): BelongsTo
    {
        return $this->belongsTo(BookingSource::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'booker_guest_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancellationRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    /**
     * @return HasMany<ReservationUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ReservationUnit::class);
    }

    /** @return HasMany<PaymentProof, $this> The payment proofs the agent sent in the portal. */
    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    /**
     * @return HasMany<FolioRoutingRule, $this>
     */
    public function routingRules(): HasMany
    {
        return $this->hasMany(FolioRoutingRule::class);
    }

    /**
     * @return HasMany<Folio, $this>
     */
    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }
}
