<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A staff note written with the line and never changed: the note of a credit move, on both
     * lines of each pair and their reversals.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->string('notes', 255)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
