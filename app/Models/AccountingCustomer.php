<?php

namespace App\Models;

use App\Enums\AccountingProvider;
use Carbon\CarbonImmutable;
use Database\Factories\AccountingCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company as a customer in the accounting software: the provider's id for it. Created once, the first time a document
 * for the company is sent.
 *
 * @property int $id
 * @property int $property_id
 * @property int $company_id
 * @property AccountingProvider $provider
 * @property string $external_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'company_id', 'provider', 'external_id'])]
class AccountingCustomer extends Model
{
    /** @use HasFactory<AccountingCustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['provider' => AccountingProvider::class];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
