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
        Schema::table('reservations', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('external_reference');
        });

        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_notes_check CHECK (notes IS NULL OR (btrim(notes) <> '' AND char_length(notes) <= 2000))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_notes_check');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
