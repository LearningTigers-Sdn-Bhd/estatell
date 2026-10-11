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
        Schema::create('rate_hour_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('hours');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['rate_id', 'hours']);
        });

        DB::statement('ALTER TABLE rate_hour_prices ADD CONSTRAINT rate_hour_prices_check CHECK (hours BETWEEN 1 AND 24 AND amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_hour_prices');
    }
};
