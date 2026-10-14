<?php

use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Services\Property\MalaysiaProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing Malaysian property the DAY_USE code. The seed action only adds the codes a property lacks.
     */
    public function up(): void
    {
        $seed = app(SeedTransactionCodes::class);

        Property::query()->where('country_code', 'MY')->lazyById()->each(fn (Property $property) => $seed($property, new MalaysiaProfile));
    }

    /**
     * Remove DAY_USE rows that no folio line uses.
     */
    public function down(): void
    {
        DB::table('transaction_codes')
            ->where('system_key', SystemTransactionCode::DayUseRevenue->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('folio_lines')->whereColumn('folio_lines.transaction_code_id', 'transaction_codes.id'))
            ->delete();
    }
};
