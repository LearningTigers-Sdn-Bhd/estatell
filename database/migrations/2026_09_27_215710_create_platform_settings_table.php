<?php

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
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
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('require_go_live_approval')->default(false);
            $table->string('default_dorm_strategy')->default(DormStrategy::BedAsUnit->value);
            $table->string('media_disk')->default(MediaDisk::Local->value);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_single_row CHECK (id = 1)');
        DB::statement("ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_default_dorm_strategy_check CHECK (default_dorm_strategy IN ('bed_as_unit', 'native_dorm'))");
        DB::statement("ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_media_disk_check CHECK (media_disk IN ('local', 'r2'))");

        DB::table('platform_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
