<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A proof an agent uploads in the portal for a booking's payment. The file is a media item.
     */
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->string('status')->default('submitted');
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['reservation_id', 'status']);
        });

        DB::statement("ALTER TABLE payment_proofs ADD CONSTRAINT payment_proofs_check CHECK (amount > 0 AND status IN ('submitted', 'accepted', 'rejected') AND (status <> 'accepted' OR payment_id IS NOT NULL) AND (status <> 'rejected' OR reject_reason IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
