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
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('pricing_basis', 20);
            $table->decimal('extra_adult_amount', 12, 2)->default(0);
            $table->decimal('extra_child_amount', 12, 2)->default(0);
            $table->decimal('single_supplement', 12, 2)->default(0);
            $table->string('early_departure_policy', 30)->default('charge_full');
            $table->boolean('needs_child_price_review')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'archived_at', 'position']);
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_pricing_basis_check CHECK (pricing_basis IN ('per_room', 'per_guest', 'per_bed', 'package'))");
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_early_departure_policy_check CHECK (early_departure_policy IN ('charge_full', 'charge_used_nights'))");
        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_amounts_check CHECK (extra_adult_amount >= 0 AND extra_child_amount >= 0 AND single_supplement >= 0)');
        DB::statement('CREATE UNIQUE INDEX rate_plans_active_name_unique ON rate_plans (property_id, lower(name)) WHERE archived_at IS NULL');

        Schema::create('rate_plan_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'room_type_id']);
            $table->index('room_type_id');
        });

        $this->backfillBasicRate();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_room_types');
        Schema::dropIfExists('rate_plans');
    }

    /**
     * Give every existing property a "Basic Rate" plan and link its active room-sold room types to it.
     */
    private function backfillBasicRate(): void
    {
        $now = now();

        DB::table('properties')->select('id')->orderBy('id')->chunkById(100, function ($properties) use ($now): void {
            foreach ($properties as $property) {
                $planId = DB::table('rate_plans')->insertGetId([
                    'property_id' => $property->id,
                    'name' => 'Basic Rate',
                    'pricing_basis' => 'per_room',
                    'position' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $roomTypeIds = DB::table('room_types')
                    ->where('property_id', $property->id)
                    ->where('sell_unit', 'room')
                    ->whereNull('archived_at')
                    ->pluck('id');

                foreach ($roomTypeIds as $roomTypeId) {
                    DB::table('rate_plan_room_types')->insert([
                        'rate_plan_id' => $planId,
                        'room_type_id' => $roomTypeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }
};
