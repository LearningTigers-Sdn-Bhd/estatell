<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the actor on mail logged before the column existed, only for the types whose actor is known. Every other row
 * stays empty. See docs/04-notifications/03-mail-log.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE mail_events SET actor_type = 'system' WHERE actor_type IS NULL AND notification_type IN ('OnlineBookingReceived', 'OnlineBookingConfirmed')");

        DB::statement(<<<'SQL'
            UPDATE mail_events AS event
            SET actor_type = CASE
                    WHEN audit.trigger = 'scheduled' THEN 'system'
                    WHEN actor.is_admin THEN 'admin'
                    WHEN actor.partner_id IS NOT NULL THEN 'partner'
                    ELSE 'property'
                END,
                actor_user_id = CASE WHEN audit.trigger = 'scheduled' THEN NULL ELSE audit.run_by END
            FROM (
                SELECT DISTINCT ON (mail.id) mail.id AS mail_id, night_audits.trigger, night_audits.run_by
                FROM mail_events AS mail
                JOIN night_audits ON night_audits.property_id = mail.property_id
                    AND night_audits.status = 'completed'
                    AND night_audits.completed_at <= mail.sent_at
                    AND night_audits.completed_at >= mail.sent_at - INTERVAL '1 hour'
                WHERE mail.actor_type IS NULL
                    AND mail.notification_type IN ('NightAuditCompleted', 'NightAuditExceptions')
                ORDER BY mail.id, night_audits.completed_at DESC
            ) AS audit
            LEFT JOIN users AS actor ON actor.id = audit.run_by
            WHERE event.id = audit.mail_id
                AND (audit.trigger = 'scheduled' OR audit.run_by IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        // The backfilled rows cannot be told apart from rows logged normally, so they stay.
    }
};
