<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events: a bill for people who are not staying. A folio belongs to a reservation or to an event, never both.
     * See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('name', 150);
            $table->foreignId('payer_guest_id')->nullable()->constrained('guests')->restrictOnDelete();
            $table->foreignId('payer_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'starts_on']);
        });

        DB::statement('ALTER TABLE events ADD CONSTRAINT events_payer_check CHECK ((payer_guest_id IS NULL) <> (payer_company_id IS NULL))');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_dates_check CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('tentative', 'confirmed', 'completed', 'cancelled'))");

        DB::statement('ALTER TABLE document_sequences DROP CONSTRAINT document_sequences_type_check');
        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_type_check CHECK (type IN ('reservation', 'folio', 'invoice', 'credit_note', 'receipt', 'registration_card', 'event'))");

        Schema::table('folios', function (Blueprint $table) {
            $table->unsignedBigInteger('reservation_id')->nullable()->change();
            $table->foreignId('event_id')->nullable()->after('reservation_id')->constrained()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_type_check');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_type_check CHECK (type IN ('master', 'unit', 'third_party', 'event'))");
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_owner_check CHECK ((reservation_id IS NULL) <> (event_id IS NULL))');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_event_check CHECK ((type = 'event') = (event_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX folios_active_event_unique ON folios (event_id) WHERE event_id IS NOT NULL AND status <> 'voided'");
    }

    /**
     * Reverse the migrations. Fails while an event folio exists.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX folios_active_event_unique');
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_event_check');
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_owner_check');
        DB::statement('ALTER TABLE folios DROP CONSTRAINT folios_type_check');
        DB::statement("ALTER TABLE folios ADD CONSTRAINT folios_type_check CHECK (type IN ('master', 'unit', 'third_party'))");

        Schema::table('folios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
            $table->unsignedBigInteger('reservation_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE document_sequences DROP CONSTRAINT document_sequences_type_check');
        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_type_check CHECK (type IN ('reservation', 'folio', 'invoice', 'credit_note', 'receipt', 'registration_card'))");

        Schema::dropIfExists('events');
    }
};
