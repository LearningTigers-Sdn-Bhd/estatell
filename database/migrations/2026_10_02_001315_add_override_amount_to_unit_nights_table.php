<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unit_nights', function (Blueprint $table) {
            $table->decimal('override_amount', 12, 2)->nullable()->after('discount_amount');
        });

        DB::statement('ALTER TABLE unit_nights DROP CONSTRAINT unit_nights_amount_total_check');
        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_override_amount_check CHECK (override_amount IS NULL OR override_amount >= 0)');
        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_amount_total_check CHECK (amount = COALESCE(override_amount, base_amount + extra_amount + supplement_amount - discount_amount))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE unit_nights DROP CONSTRAINT unit_nights_amount_total_check');
        DB::statement('ALTER TABLE unit_nights DROP CONSTRAINT unit_nights_override_amount_check');

        Schema::table('unit_nights', function (Blueprint $table) {
            $table->dropColumn('override_amount');
        });

        DB::statement('ALTER TABLE unit_nights ADD CONSTRAINT unit_nights_amount_total_check CHECK (amount = base_amount + extra_amount + supplement_amount - discount_amount)');
    }
};
