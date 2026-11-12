<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_unit_tax_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->string('paid_to', 60)->nullable();
            $table->string('reference', 60);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['reservation_unit_id', 'tax_id']);
            $table->index(['property_id', 'tax_id']);
        });

        DB::statement("ALTER TABLE reservation_unit_tax_waivers ADD CONSTRAINT reservation_unit_tax_waivers_reason_check CHECK (reason IN ('paid_elsewhere', 'guest_receipt'))");
        DB::statement("ALTER TABLE reservation_unit_tax_waivers ADD CONSTRAINT reservation_unit_tax_waivers_paid_to_check CHECK ((reason = 'paid_elsewhere') = (paid_to IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_unit_tax_waivers');
    }
};
