<?php

use App\Enums\OtaCredentialStatus;
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
        Schema::create('property_ota_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('ota_channel_id')->constrained('ota_channels')->restrictOnDelete();
            $table->string('channel_name', 80)->nullable();
            $table->string('property_code', 80);
            $table->text('username');
            $table->text('password');
            $table->string('market_manager_name', 255)->nullable();
            $table->string('market_manager_phone', 40)->nullable();
            $table->string('market_manager_email', 255)->nullable();
            $table->string('status', 20)->default(OtaCredentialStatus::Pending->value);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE property_ota_credentials ADD CONSTRAINT property_ota_credentials_status_check CHECK (status IN ('pending', 'processed'))");
        DB::statement("CREATE UNIQUE INDEX property_ota_credentials_one_per_channel_unique ON property_ota_credentials (property_id, ota_channel_id, lower(coalesce(channel_name, '')))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_ota_credentials');
    }
};
