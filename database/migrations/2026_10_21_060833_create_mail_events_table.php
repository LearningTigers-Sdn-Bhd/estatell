<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notification_type')->nullable();
            $table->text('recipient');
            $table->unsignedSmallInteger('recipient_count')->default(1);
            $table->text('subject');
            $table->string('status');
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index('sent_at');
            $table->index(['status', 'sent_at']);
        });

        DB::statement("ALTER TABLE mail_events ADD CONSTRAINT mail_events_status_check CHECK (status IN ('sent', 'failed', 'suppressed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
    }
};
