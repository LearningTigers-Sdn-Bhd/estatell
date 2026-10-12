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
        Schema::table('charge_items', function (Blueprint $table) {
            $table->boolean('auto_add')->default(false)->after('allow_override');
        });

        DB::statement('ALTER TABLE charge_items ADD CONSTRAINT charge_items_auto_add_check CHECK (NOT auto_add OR default_amount IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE charge_items DROP CONSTRAINT charge_items_auto_add_check');

        Schema::table('charge_items', function (Blueprint $table) {
            $table->dropColumn('auto_add');
        });
    }
};
