<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mail_events DROP CONSTRAINT mail_events_status_check');
        DB::statement("ALTER TABLE mail_events ADD CONSTRAINT mail_events_status_check CHECK (status IN ('sent', 'delivered', 'bounced', 'complained', 'failed', 'suppressed'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE mail_events SET status = 'sent' WHERE status IN ('delivered', 'bounced', 'complained')");
        DB::statement('ALTER TABLE mail_events DROP CONSTRAINT mail_events_status_check');
        DB::statement("ALTER TABLE mail_events ADD CONSTRAINT mail_events_status_check CHECK (status IN ('sent', 'failed', 'suppressed'))");
    }
};
