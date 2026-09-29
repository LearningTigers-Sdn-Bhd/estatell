<?php

use App\Actions\Staff\SyncPermissions;
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
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'completed_at', 'ends_on']);
            $table->index(['room_id', 'starts_on', 'ends_on']);
            $table->index('bed_id');
            $table->index('created_by');
        });

        DB::statement("ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_reason_check CHECK (reason IN ('maintenance', 'owner_use', 'out_of_order', 'other'))");
        DB::statement('ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_dates_check CHECK (ends_on >= starts_on AND ends_on - starts_on < 365)');
        DB::statement("ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_notes_check CHECK (reason <> 'other' OR btrim(coalesce(notes, '')) <> '')");

        Schema::create('room_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('clean');
            $table->boolean('is_priority')->default(false);
            $table->string('priority_note', 200)->nullable();
            $table->date('do_not_disturb_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index('assigned_to');
            $table->index('last_changed_by');
        });

        DB::statement("ALTER TABLE room_statuses ADD CONSTRAINT room_statuses_status_check CHECK (status IN ('clean', 'dirty', 'inspected'))");

        DB::statement("INSERT INTO room_statuses (property_id, room_id, status, created_at, updated_at) SELECT property_id, id, 'clean', now(), now() FROM rooms");

        app(SyncPermissions::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_statuses');
        Schema::dropIfExists('room_blocks');
    }
};
