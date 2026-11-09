<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_events', function (Blueprint $table) {
            $table->string('actor_type')->nullable()->after('property_id');
            $table->foreignId('actor_user_id')->nullable()->after('actor_type')->constrained('users')->nullOnDelete();

            $table->index(['property_id', 'sent_at']);
        });

        DB::statement("ALTER TABLE mail_events ADD CONSTRAINT mail_events_actor_type_check CHECK (actor_type IN ('system', 'property', 'partner', 'admin', 'guest', 'company'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mail_events DROP CONSTRAINT mail_events_actor_type_check');

        Schema::table('mail_events', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'sent_at']);
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropColumn('actor_type');
        });
    }
};
