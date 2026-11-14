<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result');
            $table->text('ip_address');
            $table->char('country_code', 2)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('browser', 40)->nullable();
            $table->timestamp('occurred_at');

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['result', 'occurred_at']);
        });

        DB::statement("ALTER TABLE sign_in_events ADD CONSTRAINT sign_in_events_result_check CHECK (result IN ('succeeded', 'failed', 'two_factor_failed', 'locked_out'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_events');
    }
};
