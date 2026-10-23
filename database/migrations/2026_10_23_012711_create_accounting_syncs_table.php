<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_date_id')->constrained()->restrictOnDelete();
            $table->string('provider');
            $table->string('status');
            $table->string('external_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'business_date_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_syncs');
    }
};
