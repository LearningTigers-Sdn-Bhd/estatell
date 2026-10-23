<?php

namespace App\Models;

use App\Enums\AccountingProvider;
use Carbon\CarbonImmutable;
use Database\Factories\AccountingConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A property's link to its accounting software. One row per property. The `api_key` is stored encrypted and is
 * never sent to the browser. `guest_ledger_account_code` is the account that takes the difference between the day's
 * charges and payments, so every journal balances.
 *
 * @property int $id
 * @property int $property_id
 * @property AccountingProvider $provider
 * @property string|null $api_key
 * @property string|null $company_subdomain
 * @property bool $sandbox
 * @property string|null $guest_ledger_account_code
 * @property bool $enabled
 * @property bool $push_company_billing
 * @property CarbonImmutable|null $company_billing_since
 * @property CarbonImmutable|null $last_tested_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'api_key', 'company_subdomain', 'sandbox', 'guest_ledger_account_code', 'enabled', 'push_company_billing', 'company_billing_since', 'last_tested_at', 'last_error'])]
class AccountingConnection extends Model
{
    /** @use HasFactory<AccountingConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'provider' => AccountingProvider::class,
            'api_key' => 'encrypted',
            'sandbox' => 'boolean',
            'enabled' => 'boolean',
            'push_company_billing' => 'boolean',
            'company_billing_since' => 'immutable_date',
            'last_tested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
