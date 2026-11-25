<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS floor plan: floors and tables per outlet, plus the table an order sits on. Orders keep a restrict
     * foreign key to their table so a table with history cannot be deleted. See docs/34-native-pos/03-floors-and-orders.md.
     */
    public function up(): void
    {
        Schema::create('pos_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_floors_name_unique ON pos_floors (pos_outlet_id, lower(name))');

        Schema::create('pos_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_floor_id')->constrained('pos_floors')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('label', 40)->nullable();
            $table->unsignedSmallInteger('seats')->default(4);
            $table->string('shape', 10)->default('square');
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->unsignedSmallInteger('width')->default(100);
            $table->unsignedSmallInteger('height')->default(100);
            $table->string('static_qr_token', 32)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX pos_tables_code_unique ON pos_tables (pos_floor_id, upper(code))');
        DB::statement("ALTER TABLE pos_tables ADD CONSTRAINT pos_tables_shape_check CHECK (shape IN ('square', 'round', 'rect'))");
        DB::statement('ALTER TABLE pos_tables ADD CONSTRAINT pos_tables_seats_check CHECK (seats >= 1)');

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->foreignId('pos_table_id')->nullable()->after('parent_order_id')->constrained('pos_tables')->restrictOnDelete();
            $table->index(['property_id', 'pos_table_id', 'status']);
        });
    }
};
