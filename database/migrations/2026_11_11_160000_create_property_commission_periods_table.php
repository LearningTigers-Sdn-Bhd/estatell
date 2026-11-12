<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The periods each property is charged commission for a service, replacing the one platform start date.
 * See docs/30-commission-fees/02-commission-rules.md#charging-periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_commission_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('service', 30);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'service', 'starts_on']);
        });

        DB::statement("ALTER TABLE property_commission_periods ADD CONSTRAINT property_commission_periods_service_check CHECK (service IN ('booking_engine', 'channel_manager'))");
        DB::statement('ALTER TABLE property_commission_periods ADD CONSTRAINT property_commission_periods_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('CREATE UNIQUE INDEX property_commission_periods_one_open ON property_commission_periods (property_id, service) WHERE ends_on IS NULL');

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('channel_manager_commission_from');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->date('channel_manager_commission_from')->nullable()->after('media_disk');
        });

        Schema::dropIfExists('property_commission_periods');
    }
};
