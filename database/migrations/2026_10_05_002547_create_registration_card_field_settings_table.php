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
        Schema::create('registration_card_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('field', 40);
            $table->string('visibility', 10);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['property_id', 'field']);
        });

        DB::statement("ALTER TABLE registration_card_fields ADD CONSTRAINT registration_card_fields_visibility_check CHECK (visibility IN ('hidden', 'optional', 'required'))");

        $this->backfillDefaults();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_card_fields');
    }

    private function backfillDefaults(): void
    {
        $now = now();
        $defaults = [
            'full_name' => 'required',
            'nationality' => 'required',
            'identity_document' => 'required',
            'date_of_birth' => 'optional',
            'gender' => 'hidden',
            'address' => 'optional',
            'phone' => 'required',
            'email' => 'optional',
            'vehicle_plate' => 'optional',
            'marketing_consent' => 'optional',
            'booking_number' => 'required',
            'check_in' => 'required',
            'check_out' => 'required',
            'nights' => 'required',
            'guest_count' => 'required',
            'room' => 'required',
            'rate_per_night' => 'hidden',
            'total_charges' => 'hidden',
        ];

        DB::table('properties')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now, $defaults): void {
                foreach ($properties as $property) {
                    $position = 0;

                    foreach ($defaults as $field => $visibility) {
                        DB::table('registration_card_fields')->insert([
                            'property_id' => $property->id,
                            'field' => $field,
                            'visibility' => $visibility,
                            'position' => $position++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
