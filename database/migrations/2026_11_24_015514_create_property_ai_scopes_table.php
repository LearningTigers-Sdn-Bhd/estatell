<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI assistant's scopes for each property (allowed by the platform admin, enabled by the hotel), and the handover
 * message and monthly cap on property_settings. The scope column has no CHECK, so a new AiScope case needs no migration.
 * See docs/34-ai-assistant/01-overview.md#scopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_ai_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 40);
            $table->boolean('allowed')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['property_id', 'scope']);
        });

        Schema::table('property_settings', function (Blueprint $table) {
            $table->text('ai_handover_message')->nullable();
            $table->unsignedBigInteger('ai_monthly_cap_micros')->nullable();
        });

        DB::statement('ALTER TABLE property_settings ADD CONSTRAINT property_settings_ai_monthly_cap_check CHECK (ai_monthly_cap_micros IS NULL OR ai_monthly_cap_micros > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE property_settings DROP CONSTRAINT IF EXISTS property_settings_ai_monthly_cap_check');

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_handover_message', 'ai_monthly_cap_micros']);
        });

        Schema::dropIfExists('property_ai_scopes');
    }
};
