<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu schedules of Native POS phase 2: categories or items an outlet sells only at certain days and times (breakfast,
     * happy hour). `days_of_week` is a bitmask, Monday 1 to Sunday 64. See docs/34-native-pos/02-menu-catalog.md.
     */
    public function up(): void
    {
        Schema::create('pos_menu_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('meal_period_id')->nullable()->constrained('meal_periods')->nullOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('days_of_week')->default(127);
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['pos_outlet_id', 'active']);
        });

        DB::statement('ALTER TABLE pos_menu_schedules ADD CONSTRAINT pos_menu_schedules_days_check CHECK (days_of_week BETWEEN 1 AND 127)');

        Schema::create('pos_menu_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_menu_schedule_id')->constrained('pos_menu_schedules')->cascadeOnDelete();
            $table->foreignId('pos_category_id')->nullable()->constrained('pos_categories')->cascadeOnDelete();
            $table->foreignId('pos_item_id')->nullable()->constrained('pos_items')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE pos_menu_schedule_items ADD CONSTRAINT pos_menu_schedule_items_target_check CHECK ((pos_category_id IS NOT NULL) <> (pos_item_id IS NOT NULL))');
    }
};
