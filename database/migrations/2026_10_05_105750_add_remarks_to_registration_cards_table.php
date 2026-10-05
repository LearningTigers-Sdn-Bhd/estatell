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
        Schema::table('registration_cards', function (Blueprint $table) {
            $table->string('remarks', 500)->nullable()->after('vehicle_plate');
        });

        $this->backfillField();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('registration_card_fields')->where('field', 'remarks')->delete();

        Schema::table('registration_cards', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }

    /**
     * Add the Remarks row to each property, right after Vehicle plate. Later fields move down by one.
     */
    private function backfillField(): void
    {
        $now = now();

        DB::table('properties')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($properties) use ($now): void {
                foreach ($properties as $property) {
                    $rows = DB::table('registration_card_fields')->where('property_id', $property->id);

                    if ((clone $rows)->where('field', 'remarks')->exists()) {
                        continue;
                    }

                    $after = (clone $rows)->where('field', 'vehicle_plate')->value('position');

                    if ($after === null) {
                        continue;
                    }

                    (clone $rows)->where('position', '>', $after)->increment('position');

                    DB::table('registration_card_fields')->insert([
                        'property_id' => $property->id,
                        'field' => 'remarks',
                        'visibility' => 'optional',
                        'position' => $after + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }
};
