<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One About text per property, edited on the property profile. The booking website and the Concierge guide both read it.
 * It replaces `property_settings.about` (Concierge) and `property_booking_sites.about_text` (booking website). When both
 * are filled, the Concierge text wins because it is the longer, guest-facing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('about')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE properties SET about = COALESCE(
                (SELECT NULLIF(TRIM(s.about), '') FROM property_settings s WHERE s.property_id = properties.id),
                (SELECT NULLIF(TRIM(b.about_text), '') FROM property_booking_sites b WHERE b.property_id = properties.id)
            )
            SQL);

        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('about');
        });

        Schema::table('property_booking_sites', function (Blueprint $table) {
            $table->dropColumn('about_text');
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->text('about')->nullable();
        });

        Schema::table('property_booking_sites', function (Blueprint $table) {
            $table->text('about_text')->nullable();
        });

        DB::statement('UPDATE property_settings SET about = (SELECT p.about FROM properties p WHERE p.id = property_settings.property_id)');
        DB::statement('UPDATE property_booking_sites SET about_text = (SELECT p.about FROM properties p WHERE p.id = property_booking_sites.property_id)');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('about');
        });
    }
};
