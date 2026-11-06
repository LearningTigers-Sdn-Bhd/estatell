<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A booking engine booking has no staff member, so `created_by` and `received_by` may be empty. Every property gets
     * the system Booking Engine source. A property whose own source already has that name keeps it, and the new row is
     * named "Booking Engine (Online)". See docs/12-reservations/11-booking-sources.md#booking-engine-source.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN received_by DROP NOT NULL');

        DB::statement('ALTER TABLE booking_sources DROP CONSTRAINT booking_sources_type_check');
        DB::statement('ALTER TABLE booking_sources DROP CONSTRAINT booking_sources_system_type_check');
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_type_check CHECK (type IN ('direct', 'ota', 'company', 'walk_in', 'booking_engine'))");
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_system_type_check CHECK (type NOT IN ('company', 'walk_in', 'booking_engine') OR is_system)");

        DB::statement(<<<'SQL'
            INSERT INTO booking_sources (property_id, name, type, is_system, is_active, sort_order, badge_icon, badge_color, created_at, updated_at)
            SELECT
                properties.id,
                CASE WHEN EXISTS (
                    SELECT 1 FROM booking_sources AS existing
                    WHERE existing.property_id = properties.id AND lower(existing.name) = 'booking engine'
                ) THEN 'Booking Engine (Online)' ELSE 'Booking Engine' END,
                'booking_engine', true, true, 1002, 'globe', 'violet', now(), now()
            FROM properties
            SQL);
    }

    /**
     * Fails on purpose once a booking engine booking or payment exists, because those rows have no other shape.
     */
    public function down(): void
    {
        DB::table('booking_sources')->where('type', 'booking_engine')->delete();

        DB::statement('ALTER TABLE booking_sources DROP CONSTRAINT booking_sources_type_check');
        DB::statement('ALTER TABLE booking_sources DROP CONSTRAINT booking_sources_system_type_check');
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_type_check CHECK (type IN ('direct', 'ota', 'company', 'walk_in'))");
        DB::statement("ALTER TABLE booking_sources ADD CONSTRAINT booking_sources_system_type_check CHECK (type NOT IN ('company', 'walk_in') OR is_system)");

        DB::statement('ALTER TABLE reservations ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN received_by SET NOT NULL');
    }
};
