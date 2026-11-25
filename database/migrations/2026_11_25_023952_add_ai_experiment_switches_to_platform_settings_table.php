<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform switches for the experimental AI assistant places. See docs/34-ai-assistant/01-overview.md#experimental-places.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('ai_booking_website_enabled')->default(false);
            $table->boolean('ai_staff_helper_enabled')->default(false);
        });

        Cache::forget('platform_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_booking_website_enabled', 'ai_staff_helper_enabled']);
        });

        Cache::forget('platform_settings');
    }
};
