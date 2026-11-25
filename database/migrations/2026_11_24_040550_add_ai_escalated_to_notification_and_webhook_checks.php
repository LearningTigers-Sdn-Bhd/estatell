<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The AI assistant handover: the `ai_thread_escalated` staff notification and the `ai.escalated` webhook event.
 * See docs/35-ai-assistant/03-places-and-routes.md#handover.
 */
return new class extends Migration
{
    private const string OLD_TYPES = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used', 'go_live_approved', 'go_live_rejected', 'portal_booking_created', 'portal_booking_cancelled', 'portal_cancellation_requested', 'channel_booking_received', 'channel_booking_cancelled', 'channel_removal_blocked', 'nearby_place_removed', 'online_booking_received', 'online_refund_needs_attention', 'online_booking_cancelled'";

    private const string NEW_TYPES = self::OLD_TYPES.", 'ai_thread_escalated'";

    private const array EVENTS = ['booking.created', 'booking.updated', 'booking.cancelled', 'booking.no_show', 'stay.checked_in', 'stay.checked_out', 'payment.received', 'payment.refunded', 'folio.closed', 'guest.created', 'guest.updated', 'guest.merged', 'guest.anonymized', 'room.cleaned', 'room.maintenance_flagged', 'night_audit.completed', 'channel.alert', 'stay.welcome_ready'];

    public function up(): void
    {
        $this->replaceTypeChecks(self::NEW_TYPES);
        $this->replaceEventChecks([...self::EVENTS, 'ai.escalated']);
    }

    public function down(): void
    {
        DB::table('property_notification_settings')->where('type', 'ai_thread_escalated')->delete();
        DB::table('user_notification_preferences')->where('type', 'ai_thread_escalated')->delete();
        DB::table('webhook_endpoint_events')->where('event', 'ai.escalated')->delete();
        DB::table('webhook_deliveries')->where('event', 'ai.escalated')->delete();

        $this->replaceTypeChecks(self::OLD_TYPES);
        $this->replaceEventChecks(self::EVENTS);
    }

    private function replaceTypeChecks(string $types): void
    {
        foreach (['property_notification_settings', 'user_notification_preferences'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$types}))");
        }
    }

    /**
     * @param  list<string>  $events
     */
    private function replaceEventChecks(array $events): void
    {
        $list = "'".implode("', '", $events)."'";

        foreach (['webhook_endpoint_events', 'webhook_deliveries'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_event_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_event_check CHECK (event IN ({$list}))");
        }
    }
};
