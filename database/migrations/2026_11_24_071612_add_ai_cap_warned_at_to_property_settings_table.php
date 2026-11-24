<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI assistant's 80% cap notice: when staff were last told, so they are told once a month, and the
 * `ai_cap_nearly_reached` notification type. See docs/34-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
return new class extends Migration
{
    private const string OLD_TYPES = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used', 'go_live_approved', 'go_live_rejected', 'portal_booking_created', 'portal_booking_cancelled', 'portal_cancellation_requested', 'channel_booking_received', 'channel_booking_cancelled', 'channel_removal_blocked', 'nearby_place_removed', 'online_booking_received', 'online_refund_needs_attention', 'online_booking_cancelled', 'ai_thread_escalated'";

    private const string NEW_TYPES = self::OLD_TYPES.", 'ai_cap_nearly_reached'";

    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->timestamp('ai_cap_warned_at')->nullable();
        });

        $this->replaceTypeChecks(self::NEW_TYPES);
    }

    public function down(): void
    {
        DB::table('property_notification_settings')->where('type', 'ai_cap_nearly_reached')->delete();
        DB::table('user_notification_preferences')->where('type', 'ai_cap_nearly_reached')->delete();
        $this->replaceTypeChecks(self::OLD_TYPES);

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('ai_cap_warned_at');
        });
    }

    private function replaceTypeChecks(string $types): void
    {
        foreach (['property_notification_settings', 'user_notification_preferences'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$types}))");
        }
    }
};
