<?php

namespace App\Models;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EInvoiceSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $property_id
 * @property int|null $folio_id
 * @property int|null $ar_invoice_id
 * @property int|null $reservation_id
 * @property int|null $company_id
 * @property int|null $original_submission_id
 * @property EInvoiceDocumentType $document_type
 * @property EInvoiceScenario $scenario
 * @property EInvoiceStatus $status
 * @property string $internal_id
 * @property string|null $uuid
 * @property string|null $submission_uid
 * @property string|null $long_id
 * @property array<string, mixed>|null $buyer_snapshot
 * @property array<string, mixed>|null $document_payload
 * @property array<string, mixed>|null $raw_response
 * @property array<string, mixed>|null $error_details
 * @property bool $is_consolidated
 * @property bool $requested_by_guest
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Property $property
 * @property-read Folio|null $folio
 * @property-read ArInvoice|null $arInvoice
 * @property-read Reservation|null $reservation
 * @property-read Company|null $company
 * @property-read self|null $originalSubmission
 * @property-read Collection<int, self> $adjustments
 */
#[Fillable([
    'property_id',
    'folio_id',
    'ar_invoice_id',
    'reservation_id',
    'company_id',
    'original_submission_id',
    'document_type',
    'scenario',
    'status',
    'internal_id',
    'uuid',
    'submission_uid',
    'long_id',
    'buyer_snapshot',
    'document_payload',
    'raw_response',
    'error_details',
    'is_consolidated',
    'requested_by_guest',
    'cancel_reason',
    'submitted_at',
    'validated_at',
    'cancelled_at',
])]
class EInvoiceSubmission extends Model
{
    /** @use HasFactory<EInvoiceSubmissionFactory> */
    use HasFactory;

    /**
     * LHDN permits document cancellation strictly within 72 hours of validation.
     */
    public const int CANCELLATION_WINDOW_HOURS = 72;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => EInvoiceDocumentType::class,
            'scenario' => EInvoiceScenario::class,
            'status' => EInvoiceStatus::class,
            'buyer_snapshot' => 'array',
            'document_payload' => 'array',
            'raw_response' => 'array',
            'error_details' => 'array',
            'is_consolidated' => 'boolean',
            'requested_by_guest' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * @return BelongsToMany<Folio, $this>
     */
    public function folios(): BelongsToMany
    {
        return $this->belongsToMany(Folio::class, 'e_invoice_submission_folios', 'submission_id', 'folio_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<ArInvoice, $this>
     */
    public function arInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function originalSubmission(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_submission_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'original_submission_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    /**
     * Submissions that stand on LHDN or may still stand: not Cancelled and not rejected as Invalid. An Invalid
     * document was never accepted, so it does not block a corrected resubmission.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', [EInvoiceStatus::Cancelled, EInvoiceStatus::Invalid]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where('status', EInvoiceStatus::Valid);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePendingOrSubmitted(Builder $query): void
    {
        $query->whereIn('status', [EInvoiceStatus::Pending, EInvoiceStatus::Submitted]);
    }

    public function isValid(): bool
    {
        return $this->status === EInvoiceStatus::Valid;
    }

    public function isCancelled(): bool
    {
        return $this->status === EInvoiceStatus::Cancelled;
    }

    public function isRetryable(): bool
    {
        return $this->status === EInvoiceStatus::Invalid ||
            ($this->status === EInvoiceStatus::Pending && empty($this->uuid) && $this->created_at?->diffInMinutes() > 2);
    }

    public function isCancellable(): bool
    {
        if (! $this->isValid() || $this->cancelled_at !== null || empty($this->uuid) || $this->validated_at === null) {
            return false;
        }

        if (CarbonImmutable::now()->isAfter($this->validated_at->addHours(self::CANCELLATION_WINDOW_HOURS))) {
            return false;
        }

        // Check if an active credit or debit note references this submission
        return ! $this->adjustments()
            ->where('status', '!=', EInvoiceStatus::Cancelled->value)
            ->exists();
    }

    public function cancellationDeadline(): ?CarbonImmutable
    {
        return $this->validated_at?->addHours(self::CANCELLATION_WINDOW_HOURS);
    }

    public function validationUrl(): ?string
    {
        if (empty($this->uuid) || empty($this->long_id)) {
            return null;
        }

        $portalUrl = $this->property->eInvoiceSetting?->api_environment?->portalUrl()
            ?? EInvoiceEnvironment::Sandbox->portalUrl();

        return "{$portalUrl}/{$this->uuid}/share/{$this->long_id}";
    }

    public function totalAmount(): ?float
    {
        $payload = $this->document_payload;
        if (! is_array($payload)) {
            return null;
        }

        // A credit note is sent under the Invoice root too, with type code 02.
        $amount = data_get($payload, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._') ?? data_get($payload, 'CreditNote.0.LegalMonetaryTotal.0.PayableAmount.0._');

        return $amount !== null ? (float) $amount : null;
    }

    /**
     * @return list<string>
     */
    public function errorMessages(): array
    {
        if (empty($this->error_details)) {
            return [];
        }

        $details = data_get($this->error_details, 'rejected.error.details', []);
        $messages = [];

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (! empty($detail['message'])) {
                    $messages[] = (string) $detail['message'];
                }
            }
        }

        if (! empty($messages)) {
            return $messages;
        }

        // A document LHDN accepted and then failed in validation lists its reasons per validation step.
        foreach (data_get($this->error_details, 'validationResults.validationSteps', []) as $step) {
            if (($step['status'] ?? null) !== 'Invalid') {
                continue;
            }

            $inner = data_get($step, 'error.innerError', []);
            if (is_array($inner) && $inner !== []) {
                foreach ($inner as $error) {
                    if (! empty($error['error'])) {
                        $messages[] = (string) $error['error'];
                    }
                }
            } elseif (! empty(data_get($step, 'error.error'))) {
                $messages[] = (string) data_get($step, 'error.error');
            }
        }

        if (! empty($messages)) {
            return $messages;
        }

        $directMessage = data_get($this->error_details, 'message');
        if (! empty($directMessage)) {
            return [(string) $directMessage];
        }

        return [];
    }
}
