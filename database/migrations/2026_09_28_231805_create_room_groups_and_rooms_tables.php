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
        Schema::create('room_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX room_groups_name_unique ON room_groups (property_id, lower(name))');
        DB::statement("ALTER TABLE room_groups ADD CONSTRAINT room_groups_name_check CHECK (name = btrim(name) AND name <> '')");

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'archived_at', 'position']);
            $table->index('room_type_id');
            $table->index('room_group_id');
        });

        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_number_check CHECK (number = btrim(number) AND number <> '')");
        DB::statement('CREATE UNIQUE INDEX rooms_active_number_unique ON rooms (property_id, lower(number)) WHERE archived_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_groups');
    }
};
