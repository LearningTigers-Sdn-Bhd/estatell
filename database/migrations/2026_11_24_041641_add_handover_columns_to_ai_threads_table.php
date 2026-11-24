<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI chats inbox: the model's handover summary, and who resolved the handover and when. The 90-day text purge clears
 * the summary. See docs/34-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_threads', function (Blueprint $table) {
            $table->text('handover_summary')->nullable()->after('escalated_at');
            $table->timestamp('resolved_at')->nullable()->after('handover_summary');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['handover_summary', 'resolved_at']);
        });
    }
};
