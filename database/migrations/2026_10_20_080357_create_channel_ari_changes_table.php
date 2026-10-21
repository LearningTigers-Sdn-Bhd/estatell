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
        Schema::create('channel_ari_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->timestamp('sent_at')->nullable();
            $table->string('task_id', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_property_id', 'sent_at']);
        });

        DB::statement('alter table channel_ari_changes add constraint channel_ari_changes_range_check check (date_to >= date_from)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_ari_changes');
    }
};
