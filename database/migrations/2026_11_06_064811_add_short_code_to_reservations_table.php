<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Random\Randomizer;

/**
 * Gives every reservation a short booking code, including the ones that exist already. The column holds up to 12
 * characters, so codes can grow without a schema change. See docs/12-reservations/01-reservation-shape.md.
 */
return new class extends Migration
{
    /** Keep in step with `ReservationShortCode`. A migration does not depend on application classes. */
    private const string CHARACTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const int LENGTH = 6;

    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('short_code', 12)->nullable()->after('reference');
        });

        $randomizer = new Randomizer;

        DB::table('reservations')->select(['id', 'property_id'])->orderBy('id')->each(function (object $reservation) use ($randomizer): void {
            do {
                $code = $randomizer->getBytesFromString(self::CHARACTERS, self::LENGTH);
            } while (DB::table('reservations')->where('property_id', $reservation->property_id)->where('short_code', $code)->exists());

            DB::table('reservations')->where('id', $reservation->id)->update(['short_code' => $code]);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('short_code', 12)->nullable(false)->change();
            $table->unique(['property_id', 'short_code']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['property_id', 'short_code']);
            $table->dropColumn('short_code');
        });
    }
};
