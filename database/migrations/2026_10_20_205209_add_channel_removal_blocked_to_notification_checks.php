<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string OLD_TYPES = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used', 'go_live_approved', 'go_live_rejected', 'portal_booking_created', 'portal_booking_cancelled', 'portal_cancellation_requested', 'channel_booking_received', 'channel_booking_cancelled'";

    private const string NEW_TYPES = self::OLD_TYPES.", 'channel_removal_blocked'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceChecks(self::NEW_TYPES);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $types = ['channel_removal_blocked'];

        DB::table('property_notification_settings')->whereIn('type', $types)->delete();
        DB::table('user_notification_preferences')->whereIn('type', $types)->delete();

        $this->replaceChecks(self::OLD_TYPES);
    }

    private function replaceChecks(string $types): void
    {
        foreach (['property_notification_settings', 'user_notification_preferences'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$types}))");
        }
    }
};
