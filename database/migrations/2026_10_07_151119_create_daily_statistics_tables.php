<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('rooms_available');
            $table->unsignedInteger('rooms_sold');
            $table->unsignedInteger('beds_available');
            $table->unsignedInteger('beds_sold');
            $table->decimal('occupancy', 5, 2);
            $table->decimal('adr', 12, 2);
            $table->decimal('revpar', 12, 2);
            $table->unsignedInteger('arrivals');
            $table->unsignedInteger('departures');
            $table->unsignedInteger('no_shows');
            $table->unsignedInteger('in_house_guests');
            $table->timestamps();

            $table->unique(['property_id', 'business_date']);
        });

        DB::statement('ALTER TABLE daily_statistics ADD CONSTRAINT daily_statistics_money_check CHECK (occupancy >= 0 AND adr >= 0 AND revpar >= 0)');

        Schema::create('daily_revenue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->foreignId('transaction_code_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('line_count');
            $table->timestamps();

            $table->unique(['property_id', 'business_date', 'transaction_code_id'], 'daily_revenue_property_date_code_unique');
            $table->index('transaction_code_id');
        });

        DB::statement('ALTER TABLE daily_revenue ADD CONSTRAINT daily_revenue_line_count_check CHECK (line_count > 0)');

        $this->replaceStepCheck(['claim_business_date', 'resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'snapshot_statistics', 'close_and_open_next']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceStepCheck(['claim_business_date', 'resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'close_and_open_next']);

        Schema::dropIfExists('daily_revenue');
        Schema::dropIfExists('daily_statistics');
    }

    /**
     * @param  list<string>  $steps
     */
    private function replaceStepCheck(array $steps): void
    {
        $list = implode(', ', array_map(fn (string $step): string => "'{$step}'", $steps));

        DB::statement('ALTER TABLE night_audit_steps DROP CONSTRAINT night_audit_steps_step_check');
        DB::statement("ALTER TABLE night_audit_steps ADD CONSTRAINT night_audit_steps_step_check CHECK (step IN ({$list}))");
    }
};
