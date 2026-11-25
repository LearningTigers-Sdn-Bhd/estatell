<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hotel's own OpenAI key for the AI assistant (stored encrypted by the model cast), whether it works, and when it
 * was last tested. See docs/35-ai-assistant/01-overview.md#api-key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->text('ai_openai_key')->nullable();
            $table->string('ai_key_status', 20)->nullable();
            $table->timestamp('ai_key_checked_at')->nullable();
        });

        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_ai_key_status_check CHECK (ai_key_status IS NULL OR ai_key_status IN ('working', 'failing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE property_settings DROP CONSTRAINT IF EXISTS property_settings_ai_key_status_check');

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_openai_key', 'ai_key_status', 'ai_key_checked_at']);
        });
    }
};
