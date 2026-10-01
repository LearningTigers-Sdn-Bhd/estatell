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
        Schema::create('business_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 20)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'date']);
            $table->index('closed_by');
        });

        DB::statement("ALTER TABLE business_dates ADD CONSTRAINT business_dates_status_check CHECK (status IN ('open', 'auditing', 'closed'))");
        DB::statement("ALTER TABLE business_dates ADD CONSTRAINT business_dates_closed_check CHECK ((status = 'closed') = (closed_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX business_dates_one_current_per_property ON business_dates (property_id) WHERE status <> 'closed'");

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'type', 'year']);
        });

        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_type_check CHECK (type IN ('reservation', 'folio', 'invoice', 'credit_note', 'receipt', 'registration_card'))");
        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_year_check CHECK (year BETWEEN 2000 AND 2099)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('business_dates');
    }
};
