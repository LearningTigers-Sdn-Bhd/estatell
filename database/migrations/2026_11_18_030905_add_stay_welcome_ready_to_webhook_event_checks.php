<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array EVENTS = ['booking.created', 'booking.updated', 'booking.cancelled', 'booking.no_show', 'stay.checked_in', 'stay.checked_out', 'payment.received', 'payment.refunded', 'folio.closed', 'guest.created', 'guest.updated', 'guest.merged', 'guest.anonymized', 'room.cleaned', 'room.maintenance_flagged', 'night_audit.completed', 'channel.alert'];

    /**
     * The `stay.welcome_ready` event joins the events an endpoint can hear and a delivery can carry.
     */
    public function up(): void
    {
        $this->replaceChecks([...self::EVENTS, 'stay.welcome_ready']);
    }

    public function down(): void
    {
        DB::table('webhook_endpoint_events')->where('event', 'stay.welcome_ready')->delete();
        DB::table('webhook_deliveries')->where('event', 'stay.welcome_ready')->delete();

        $this->replaceChecks(self::EVENTS);
    }

    /**
     * @param  list<string>  $events
     */
    private function replaceChecks(array $events): void
    {
        $list = "'".implode("', '", $events)."'";

        foreach (['webhook_endpoint_events', 'webhook_deliveries'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_event_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_event_check CHECK (event IN ({$list}))");
        }
    }
};
