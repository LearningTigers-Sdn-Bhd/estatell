<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time someone cleans a room: who, and their IN and OUT time. Also creates the
     * housekeeping.edit-times permission.
     */
    public function up(): void
    {
        Schema::create('room_cleanings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('housekeeper_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'date']);
        });

        DB::statement('ALTER TABLE room_cleanings ADD CONSTRAINT room_cleanings_times_check CHECK (finished_at IS NULL OR finished_at >= started_at)');
        DB::statement('CREATE UNIQUE INDEX room_cleanings_one_open ON room_cleanings (room_id) WHERE finished_at IS NULL');

        app(SyncPermissions::class)();
    }

    public function down(): void
    {
        Schema::dropIfExists('room_cleanings');
    }
};
