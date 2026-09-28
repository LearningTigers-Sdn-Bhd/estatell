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
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('label', 30);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'archived_at', 'position']);
        });

        DB::statement("ALTER TABLE beds ADD CONSTRAINT beds_label_check CHECK (label = btrim(label) AND label <> '')");
        DB::statement('CREATE UNIQUE INDEX beds_active_label_unique ON beds (room_id, lower(label)) WHERE archived_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
