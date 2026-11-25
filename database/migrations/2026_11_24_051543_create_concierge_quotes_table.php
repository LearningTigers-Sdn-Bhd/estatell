<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quote link the AI assistant sends: a room type, plan and dates for a guest to book on the Concierge page. Nothing is
 * booked until the guest confirms. See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concierge_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->unsignedSmallInteger('adults');
            $table->decimal('quoted_total', 12, 2);
            $table->foreignId('ai_turn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['property_id', 'created_at']);
        });

        DB::statement('ALTER TABLE concierge_quotes ADD CONSTRAINT concierge_quotes_dates_check CHECK (departure_date > arrival_date)');
        DB::statement('ALTER TABLE concierge_quotes ADD CONSTRAINT concierge_quotes_adults_check CHECK (adults > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_quotes');
    }
};
