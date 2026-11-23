<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A `Facility` exception names the facility booking the night audit could not post, with no unit. The step and
     * type checks gain `post_facility_charges` and `facility`. See docs/20-night-audit/03-audit-run.md.
     */
    public function up(): void
    {
        Schema::table('night_audit_exceptions', function (Blueprint $table) {
            $table->foreignId('facility_booking_id')->nullable()->after('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->index('facility_booking_id');
        });

        $this->replaceChecks(
            ['claim_business_date', 'resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'post_facility_charges', 'update_room_statuses', 'snapshot_statistics', 'close_and_open_next'],
            ['resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'post_facility_charges', 'update_room_statuses', 'close_and_open_next'],
            ['missed_arrival', 'overdue_departure', 'room_charge', 'add_on', 'facility', 'unexpected_error'],
        );

        DB::statement("ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_facility_booking_check CHECK ((type = 'facility') = (facility_booking_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE night_audit_exceptions DROP CONSTRAINT IF EXISTS night_audit_exceptions_facility_booking_check');

        $this->replaceChecks(
            ['claim_business_date', 'resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'snapshot_statistics', 'close_and_open_next'],
            ['resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'close_and_open_next'],
            ['missed_arrival', 'overdue_departure', 'room_charge', 'add_on', 'unexpected_error'],
        );

        Schema::table('night_audit_exceptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_booking_id');
        });
    }

    /**
     * @param  list<string>  $steps
     * @param  list<string>  $exceptionSteps
     * @param  list<string>  $types
     */
    private function replaceChecks(array $steps, array $exceptionSteps, array $types): void
    {
        $list = fn (array $values): string => implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));

        DB::statement('ALTER TABLE night_audit_steps DROP CONSTRAINT night_audit_steps_step_check');
        DB::statement("ALTER TABLE night_audit_steps ADD CONSTRAINT night_audit_steps_step_check CHECK (step IN ({$list($steps)}))");
        DB::statement('ALTER TABLE night_audit_exceptions DROP CONSTRAINT night_audit_exceptions_step_check');
        DB::statement("ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_step_check CHECK (step IN ({$list($exceptionSteps)}))");
        DB::statement('ALTER TABLE night_audit_exceptions DROP CONSTRAINT night_audit_exceptions_type_check');
        DB::statement("ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_type_check CHECK (type IN ({$list($types)}))");
    }
};
