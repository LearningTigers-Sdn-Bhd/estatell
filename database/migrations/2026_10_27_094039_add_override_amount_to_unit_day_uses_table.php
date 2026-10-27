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
        Schema::table('unit_day_uses', function (Blueprint $table): void {
            $table->decimal('override_amount', 12, 2)->nullable();
        });

        DB::statement('ALTER TABLE unit_day_uses ADD CONSTRAINT unit_day_uses_override_amount_check CHECK (override_amount IS NULL OR (override_amount >= 0 AND amount = override_amount))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE unit_day_uses DROP CONSTRAINT unit_day_uses_override_amount_check');

        Schema::table('unit_day_uses', function (Blueprint $table): void {
            $table->dropColumn('override_amount');
        });
    }
};
