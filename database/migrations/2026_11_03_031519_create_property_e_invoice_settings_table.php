<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_e_invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('api_environment', 20)->default('sandbox');
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->boolean('signature_enabled')->default(false);
            $table->text('signing_certificate')->nullable();
            $table->text('signing_private_key')->nullable();
            $table->text('signing_passphrase')->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->string('supplier_tin', 30)->nullable();
            $table->string('supplier_sst_registration', 30)->nullable();
            $table->string('supplier_ttx_registration', 30)->nullable();
            $table->string('supplier_msic_code', 5)->nullable();
            $table->string('supplier_business_description', 255)->nullable();
            $table->string('supplier_email', 255)->nullable();
            $table->string('supplier_phone', 30)->nullable();
            $table->string('supplier_address_line1', 255)->nullable();
            $table->string('supplier_address_line2', 255)->nullable();
            $table->string('supplier_city', 100)->nullable();
            $table->string('supplier_postal_code', 20)->nullable();
            $table->string('supplier_state_code', 2)->nullable();
            $table->boolean('auto_submit_on_checkout')->default(false);
            $table->boolean('consolidation_enabled')->default(true);
            $table->timestampTz('effective_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_e_invoice_settings');
    }
};
