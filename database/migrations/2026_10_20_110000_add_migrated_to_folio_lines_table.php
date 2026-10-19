<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Marks a line copied from the old system by the old folio page.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->boolean('migrated')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropColumn('migrated');
        });
    }
};
