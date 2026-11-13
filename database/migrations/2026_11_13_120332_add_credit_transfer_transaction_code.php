<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\CountryProfiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Credit transfer system code (`folio_transfer`) to every property. Move credit posts its pair of payment
 * lines with it. The seed action adds only the codes a property lacks. See docs/13-folio/02-posting-charges.md#move-credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seed = app(SeedTransactionCodes::class);

        Property::query()->lazyById()->each(function (Property $property) use ($seed): void {
            $seed($property, CountryProfiles::for((string) $property->country_code));
        });
    }

    public function down(): void
    {
        DB::table('transaction_codes')
            ->where('system_key', SystemTransactionCode::FolioTransfer->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('folio_lines')->whereColumn('folio_lines.transaction_code_id', 'transaction_codes.id'))
            ->delete();
    }
};
