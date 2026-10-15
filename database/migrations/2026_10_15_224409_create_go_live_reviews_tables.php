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
        Schema::create('go_live_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->string('decision', 20)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->date('opening_date')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'submitted_at']);
        });

        DB::statement('CREATE UNIQUE INDEX go_live_reviews_one_pending_per_property ON go_live_reviews (property_id) WHERE decision IS NULL');
        DB::statement("ALTER TABLE go_live_reviews ADD CONSTRAINT go_live_reviews_decision_check CHECK (decision IS NULL OR decision IN ('approved', 'rejected', 'withdrawn'))");
        DB::statement('ALTER TABLE go_live_reviews ADD CONSTRAINT go_live_reviews_decided_check CHECK ((decision IS NULL) = (decided_at IS NULL))');

        Schema::create('go_live_review_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('go_live_review_id')->constrained()->cascadeOnDelete();
            $table->string('step', 40);

            $table->unique(['go_live_review_id', 'step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('go_live_review_flags');
        Schema::dropIfExists('go_live_reviews');
    }
};
