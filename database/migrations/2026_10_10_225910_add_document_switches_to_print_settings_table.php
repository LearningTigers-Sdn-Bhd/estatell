<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The column defaults fill the rows that exist.
     */
    public function up(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->boolean('show_unit_on_lines')->default(true);
            $table->boolean('nightly_breakdown')->default(false);
            $table->boolean('tax_breakdown')->default(true);
            $table->boolean('show_amount_in_words')->default(false);
            $table->boolean('show_cancellation_policy')->default(true);
            $table->boolean('show_signature_lines')->default(false);
            $table->string('bank_details', 500)->nullable();
        });

        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_bank_details_check CHECK (bank_details IS NULL OR (bank_details = btrim(bank_details) AND bank_details <> ''))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE print_settings DROP CONSTRAINT print_settings_bank_details_check');

        Schema::table('print_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_unit_on_lines', 'nightly_breakdown', 'tax_breakdown', 'show_amount_in_words',
                'show_cancellation_policy', 'show_signature_lines', 'bank_details',
            ]);
        });
    }
};
