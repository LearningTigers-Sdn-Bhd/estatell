<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One row for each payment email sent to an agent, so each is sent once: the schedule after
     * booking, a reminder before a stage is due, and a notice when it is overdue.
     */
    public function up(): void
    {
        Schema::create('agent_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20);
            $table->string('kind', 20);
            $table->timestamp('sent_at');
            $table->unique(['reservation_id', 'stage', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_payment_reminders');
    }
};
