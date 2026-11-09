<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old mail to guests and agents has no property, but its subject names one. Fills the property only when exactly one
 * property has that name. See docs/04-notifications/03-mail-log.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE mail_events AS event
            SET property_id = property.id
            FROM (SELECT MIN(id) AS id, name FROM properties GROUP BY name HAVING COUNT(*) = 1) AS property
            WHERE event.property_id IS NULL
                AND (
                    (event.notification_type = 'OnlineBookingConfirmed' AND event.subject = 'Your booking at ' || property.name || ' is confirmed')
                    OR (event.notification_type = 'OnlineBookingCancelled' AND event.subject = 'Your booking at ' || property.name || ' is cancelled')
                    OR (event.notification_type = 'LateOnlinePaymentRefunded' AND event.subject = 'Your payment to ' || property.name || ' is being refunded')
                    OR (event.notification_type = 'AgentPaymentReminder' AND RIGHT(event.subject, LENGTH(property.name) + 3) = ' · ' || property.name)
                )
        SQL);
    }

    public function down(): void
    {
        // The filled rows cannot be told apart from rows logged normally, so they stay.
    }
};
