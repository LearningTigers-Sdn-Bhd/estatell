<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('failing_since')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'property_id']);
        });

        DB::statement('ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_disabled_check CHECK (disabled_at IS NULL OR is_active = false)');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
