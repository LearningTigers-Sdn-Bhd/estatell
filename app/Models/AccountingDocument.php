<?php

namespace App\Models;

use App\Enums\AccountingDocumentKind;
use App\Enums\AccountingDocumentStatus;
use App\Enums\AccountingProvider;
use Carbon\CarbonImmutable;
use Database\Factories\AccountingDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AR invoice, credit note or AR payment as it was sent to the accounting software. Exactly one of `ar_invoice_id`
 * and `ar_payment_id` is set. `payload_hash` is the hash of what was sent, so a receipt whose allocations changed is seen
 * and replaced. `external_type` is the provider's own document type, which QNE needs to match a receipt to an invoice.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $ar_invoice_id
 * @property int|null $ar_payment_id
 * @property AccountingProvider $provider
 * @property AccountingDocumentKind $kind
 * @property AccountingDocumentStatus $status
 * @property string|null $external_id
 * @property string|null $external_type
 * @property string|null $payload_hash
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'ar_invoice_id', 'ar_payment_id', 'provider', 'kind', 'status', 'external_id', 'external_type', 'payload_hash', 'error', 'attempts', 'pushed_at', 'voided_at'])]
class AccountingDocument extends Model
{
    /** @use HasFactory<AccountingDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'provider' => AccountingProvider::class,
            'kind' => AccountingDocumentKind::class,
            'status' => AccountingDocumentStatus::class,
            'attempts' => 'integer',
            'pushed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ArInvoice, $this>
     */
    public function arInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class);
    }

    /**
     * @return BelongsTo<ArPayment, $this>
     */
    public function arPayment(): BelongsTo
    {
        return $this->belongsTo(ArPayment::class);
    }
}
