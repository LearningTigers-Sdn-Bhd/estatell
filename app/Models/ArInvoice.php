<?php

namespace App\Models;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use Carbon\CarbonImmutable;
use Database\Factories\ArInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a company owes (an invoice, positive) or a correction of it (a credit note, negative, with
 * `credit_for_invoice_id`). Never edited after issue. Balance and overdue status are calculated, not stored.
 * See docs/15-city-ledger/02-ar-invoices.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $company_id
 * @property int|null $folio_id
 * @property int|null $credit_for_invoice_id
 * @property ArInvoiceType $type
 * @property string $number
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property string $amount
 * @property string|null $reason
 * @property bool $migrated True for an invoice brought in from the old system. It has no folio and no PDF.
 * @property ArInvoiceStatus $status
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'company_id', 'folio_id', 'credit_for_invoice_id', 'type', 'number', 'issued_on', 'due_on', 'amount', 'reason',
    'migrated', 'status', 'created_by',
])]
class ArInvoice extends Model
{
    /** @use HasFactory<ArInvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArInvoiceType::class,
            'status' => ArInvoiceStatus::class,
            'issued_on' => 'date',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'migrated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * The invoice a credit note corrects.
     *
     * @return BelongsTo<self, $this>
     */
    public function creditFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credit_for_invoice_id');
    }

    /**
     * The credit notes issued against an invoice.
     *
     * @return HasMany<self, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credit_for_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
