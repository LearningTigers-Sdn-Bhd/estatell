<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Foreign-guest taxes (tourism tax) start waivable; others (SST) do not.
     */
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->boolean('can_be_waived')->default(false)->after('collect_at_check_in');
        });

        DB::table('taxes')->where('foreign_guests_only', true)->update(['can_be_waived' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn('can_be_waived');
        });
    }
};
