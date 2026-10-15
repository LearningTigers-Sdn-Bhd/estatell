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
        Schema::create('ota_channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_other')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE ota_channels ADD CONSTRAINT ota_channels_code_lower_check CHECK (code = lower(code))');
        DB::statement("ALTER TABLE ota_channels ADD CONSTRAINT ota_channels_other_code_check CHECK (NOT is_other OR code = 'other')");
        DB::statement('CREATE UNIQUE INDEX ota_channels_one_other_unique ON ota_channels (is_other) WHERE is_other');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ota_channels');
    }
};
