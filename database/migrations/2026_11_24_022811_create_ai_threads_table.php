<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI assistant's threads, one row per model turn, and the tools each turn called. No message text here: it lives in
 * the SDK's conversation tables and is purged after 90 days, so these rows can stay for the usage report. Place, status,
 * outcome and tool have no CHECK, so a new enum case needs no migration. See docs/35-ai-assistant/04-usage-and-cost.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('place', 40);
            $table->string('status', 20);
            $table->string('agent_conversation_id', 36)->unique();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 8)->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('last_message_at');
            $table->timestamps();

            $table->index(['property_id', 'status', 'last_message_at']);
            $table->index(['reservation_unit_id', 'status']);
        });

        Schema::create('ai_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_thread_id')->constrained()->cascadeOnDelete();
            $table->string('place', 40);
            $table->string('language', 8)->nullable();
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('cached_input_tokens');
            $table->unsignedBigInteger('cost_micros');
            $table->string('outcome', 20);
            $table->unsignedInteger('duration_ms');
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['property_id', 'created_at']);
        });

        Schema::create('ai_turn_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_turn_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 40);
            $table->boolean('succeeded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_turn_tools');
        Schema::dropIfExists('ai_turns');
        Schema::dropIfExists('ai_threads');
    }
};
