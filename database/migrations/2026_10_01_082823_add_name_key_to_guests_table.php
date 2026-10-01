<?php

use App\Services\Guests\GuestName;
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
        Schema::table('guests', function (Blueprint $table) {
            $table->string('name_key', 150)->nullable()->after('full_name');
        });

        $guestName = new GuestName;

        DB::table('guests')->select(['id', 'full_name'])->orderBy('id')->each(function (object $guest) use ($guestName): void {
            DB::table('guests')->where('id', $guest->id)->update(['name_key' => $guestName->key($guest->full_name)]);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('name_key', 150)->nullable(false)->change();
            $table->index(['property_id', 'name_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'name_key']);
            $table->dropColumn('name_key');
        });
    }
};
