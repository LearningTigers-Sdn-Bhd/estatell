<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('unit_add_ons', function (Blueprint $table) {
            $table->foreignId('folio_line_id')->nullable()->after('service_date')->constrained('folio_lines')->restrictOnDelete();

            $table->index('folio_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_add_ons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folio_line_id');
        });
    }
};
