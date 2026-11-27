<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The children on an AI quote link, by age, so the quote is priced and booked with them.
 * See docs/35-ai-assistant/03-places-and-routes.md#quote-links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concierge_quote_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concierge_quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('age');

            $table->index('concierge_quote_id');
        });

        DB::statement('ALTER TABLE concierge_quote_children ADD CONSTRAINT concierge_quote_children_age_check CHECK (age >= 0 AND age <= 17)');
    }

    public function down(): void
    {
        Schema::dropIfExists('concierge_quote_children');
    }
};
