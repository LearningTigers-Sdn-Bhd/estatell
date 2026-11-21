<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The property's own list of market segments (Leisure, Corporate, Groups...) and the segment of each booking. A segment
 * is turned off, never deleted, so the bookings that used it keep it. See docs/12-reservations/15-market-segments.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX market_segments_property_name_unique ON market_segments (property_id, lower(name))');

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('market_segment_id')->nullable()->after('sales_person_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_segment_id');
        });

        Schema::dropIfExists('market_segments');
    }
};
