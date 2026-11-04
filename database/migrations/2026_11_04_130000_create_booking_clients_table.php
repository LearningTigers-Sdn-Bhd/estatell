<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_clients', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE booking_clients ADD CONSTRAINT booking_clients_type_property_check CHECK ((type = 'api-only' AND property_id IS NOT NULL) OR (type <> 'api-only' AND property_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX booking_clients_one_microsite ON booking_clients (type) WHERE type = 'microsite' AND revoked_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_clients');
    }
};
