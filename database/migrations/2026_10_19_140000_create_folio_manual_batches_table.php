<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the manual batch table and the line pointer to it, then create folio.manual and
     * folio.view-manual-entries and grant them to the default roles.
     */
    public function up(): void
    {
        Schema::create('folio_manual_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('folio_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index('property_id');
            $table->index('folio_id');
            $table->index('user_id');
        });

        Schema::table('folio_lines', function (Blueprint $table) {
            $table->foreignId('manual_batch_id')->nullable()->after('service_date')->constrained('folio_manual_batches')->restrictOnDelete();
        });

        app(SyncPermissions::class)();
    }

    /**
     * Permission rows stay. They are created from PermissionEnum, so there is nothing to undo for them.
     */
    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_batch_id');
        });

        Schema::dropIfExists('folio_manual_batches');
    }
};
