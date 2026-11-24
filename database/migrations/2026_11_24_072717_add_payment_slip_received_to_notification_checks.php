<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `payment_slip_received` notification type was never added to the preference check constraints, so saving a
 * preference for "Company payment slip" failed. See docs/04-notifications/01-notification-types.md.
 */
return new class extends Migration
{
    private const string OLD_TYPES = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used', 'go_live_approved', 'go_live_rejected', 'portal_booking_created', 'portal_booking_cancelled', 'portal_cancellation_requested', 'channel_booking_received', 'channel_booking_cancelled', 'channel_removal_blocked', 'nearby_place_removed', 'online_booking_received', 'online_refund_needs_attention', 'online_booking_cancelled', 'ai_thread_escalated', 'ai_cap_nearly_reached'";

    private const string NEW_TYPES = self::OLD_TYPES.", 'payment_slip_received'";

    public function up(): void
    {
        $this->replaceTypeChecks(self::NEW_TYPES);
    }

    public function down(): void
    {
        DB::table('property_notification_settings')->where('type', 'payment_slip_received')->delete();
        DB::table('user_notification_preferences')->where('type', 'payment_slip_received')->delete();

        $this->replaceTypeChecks(self::OLD_TYPES);
    }

    private function replaceTypeChecks(string $types): void
    {
        foreach (['property_notification_settings', 'user_notification_preferences'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$types}))");
        }
    }
};
