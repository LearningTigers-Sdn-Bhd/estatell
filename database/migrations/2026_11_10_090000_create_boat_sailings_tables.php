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
        Schema::create('boat_sailings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 10);
            $table->string('direction', 10)->nullable();
            $table->foreignId('boat_departure_id')->nullable()->constrained('boat_departures')->restrictOnDelete();
            $table->time('leaves_at')->nullable();
            $table->time('arrives_at')->nullable();
            $table->string('pickup_point')->nullable();
            $table->foreignId('boat_id')->nullable()->constrained('boats')->restrictOnDelete();
            $table->string('job_type', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'date']);
            $table->index(['boat_departure_id', 'date']);
        });

        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_kind_check CHECK (kind IN ('guests', 'job'))");
        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_direction_check CHECK (direction IS NULL OR direction IN ('in', 'out'))");
        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_direction_kind_check CHECK ((kind = 'guests') = (direction IS NOT NULL))");
        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_job_type_kind_check CHECK ((kind = 'job') = (job_type IS NOT NULL))");
        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_departure_kind_check CHECK (kind = 'guests' OR boat_departure_id IS NULL)");
        DB::statement('ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_time_required_check CHECK (boat_departure_id IS NOT NULL OR leaves_at IS NOT NULL)');
        DB::statement('ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_times_order_check CHECK (arrives_at IS NULL OR leaves_at IS NULL OR arrives_at > leaves_at)');
        DB::statement("ALTER TABLE boat_sailings ADD CONSTRAINT boat_sailings_done_kind_check CHECK (done_at IS NULL OR kind = 'job')");

        Schema::create('boat_sailing_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_sailing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_crew_member_id')->constrained()->restrictOnDelete();
            $table->boolean('in_charge')->default(false);
            $table->timestamps();

            $table->unique(['boat_sailing_id', 'boat_crew_member_id']);
        });

        DB::statement('CREATE UNIQUE INDEX boat_sailing_crew_one_in_charge_unique ON boat_sailing_crew (boat_sailing_id) WHERE in_charge');

        Schema::table('unit_boat_trips', function (Blueprint $table) {
            $table->foreignId('boat_sailing_id')->nullable()->after('boat_departure_id')->constrained('boat_sailings')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_boat_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boat_sailing_id');
        });

        Schema::dropIfExists('boat_sailing_crew');
        Schema::dropIfExists('boat_sailings');
    }
};
