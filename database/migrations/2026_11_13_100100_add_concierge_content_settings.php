<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->string('house_rules', 2000)->nullable();
            $table->boolean('concierge_refund_requests_enabled')->default(false);
        });

        DB::statement('ALTER TABLE guest_requests DROP CONSTRAINT guest_requests_type_check');
        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_type_check CHECK (type IN ('housekeeping', 'maintenance', 'complaint', 'other', 'refund'))");
    }

    public function down(): void
    {
        DB::table('guest_requests')->where('type', 'refund')->delete();
        DB::statement('ALTER TABLE guest_requests DROP CONSTRAINT guest_requests_type_check');
        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_type_check CHECK (type IN ('housekeeping', 'maintenance', 'complaint', 'other'))");

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn(['house_rules', 'concierge_refund_requests_enabled']);
        });
    }
};
