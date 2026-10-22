<?php

use App\Actions\Catalogues\SeedChargeItems;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\CountryProfiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every seeded property the DAMAGE code and its Damage charge item. Both seed actions only add what a property
     * lacks. Properties that have not finished Basics get them from `SeedPropertyDefaults` later.
     */
    public function up(): void
    {
        $seedCodes = app(SeedTransactionCodes::class);
        $seedItems = app(SeedChargeItems::class);

        Property::query()
            ->whereHas('transactionCodes')
            ->lazyById()
            ->each(function (Property $property) use ($seedCodes, $seedItems): void {
                $seedCodes($property, CountryProfiles::for((string) $property->country_code));
                $seedItems($property);
            });
    }

    /**
     * Remove the Damage items and DAMAGE codes that no folio line uses.
     */
    public function down(): void
    {
        $unused = fn ($query) => $query->selectRaw('1')->from('folio_lines')->whereColumn('folio_lines.transaction_code_id', 'transaction_codes.id');

        $codeIds = DB::table('transaction_codes')
            ->where('system_key', SystemTransactionCode::DamageRevenue->value)
            ->whereNotExists($unused)
            ->pluck('id');

        DB::table('charge_items')->whereIn('transaction_code_id', $codeIds)->delete();
        DB::table('transaction_codes')->whereIn('id', $codeIds)->delete();
    }
};
