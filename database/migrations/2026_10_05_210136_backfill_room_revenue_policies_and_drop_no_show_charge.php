<?php

use App\Actions\Catalogues\SeedRoomRevenuePolicies;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyRateBasis;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every existing property its four policy rows, copy the old no-show charge into the
     * no-show row (None is Free, First night and Full stay are 100% of the booked rate), then drop the column.
     */
    public function up(): void
    {
        $seed = app(SeedRoomRevenuePolicies::class);

        Property::query()->lazyById()->each(function (Property $property) use ($seed): void {
            $seed($property);

            $noShowCharge = DB::table('property_settings')->where('property_id', $property->id)->value('no_show_charge');

            $scope = match ($noShowCharge) {
                'first_night' => PolicyScope::FirstNight,
                'full_stay' => PolicyScope::WholeStay,
                default => null,
            };

            if ($scope === null) {
                return;
            }

            DB::table('room_revenue_policies')
                ->where('property_id', $property->id)
                ->where('code', SystemTransactionCode::NoShowRevenue->value)
                ->update([
                    'charge_mode' => PolicyChargeMode::Percentage->value,
                    'percent' => '100.00',
                    'percent_basis' => PolicyRateBasis::BookedRate->value,
                    'applies_to' => $scope->value,
                ]);
        });

        DB::statement('ALTER TABLE property_settings DROP CONSTRAINT property_settings_no_show_charge_check');

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('no_show_charge');
        });
    }

    /**
     * Restore the column from the no-show policy.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->string('no_show_charge')->default('none');
        });

        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_no_show_charge_check CHECK (no_show_charge IN ('first_night', 'full_stay', 'none'))");

        DB::table('room_revenue_policies')
            ->where('code', SystemTransactionCode::NoShowRevenue->value)
            ->where('charge_mode', PolicyChargeMode::Percentage->value)
            ->orderBy('id')
            ->each(function (object $policy): void {
                DB::table('property_settings')->where('property_id', $policy->property_id)->update([
                    'no_show_charge' => $policy->applies_to === PolicyScope::WholeStay->value ? 'full_stay' : 'first_night',
                ]);
            });
    }
};
