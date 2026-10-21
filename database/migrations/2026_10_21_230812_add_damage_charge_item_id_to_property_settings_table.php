<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The charge item a damage charge at check-out posts to. Null means the check-out Dialog offers no damage charge.
     */
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->foreignId('damage_charge_item_id')->nullable()->after('security_deposit_amount')->constrained('charge_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('damage_charge_item_id');
        });
    }
};
