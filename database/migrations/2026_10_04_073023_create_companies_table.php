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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('type', 20);
            $table->string('brn', 30)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('sst_registration_number', 30)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('billing_address_line1', 150)->nullable();
            $table->string('billing_address_line2', 150)->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state_code', 100)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->char('billing_country_code', 2)->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('direct_bill_enabled')->default(false);
            $table->boolean('portal_enabled')->default(false);
            $table->unsignedSmallInteger('portal_cancel_cutoff_days')->nullable();
            $table->boolean('profile_managed_by_company')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['property_id', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX companies_property_name_unique ON companies (property_id, lower(name))');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_name_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_type_check CHECK (type IN ('corporate', 'travel_agent', 'government'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('active', 'suspended'))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_billing_country_code_check CHECK (billing_country_code IS NULL OR billing_country_code ~ '^[A-Z]{2}$')");

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::table('folios', function (Blueprint $table) {
            $table->foreign('payer_company_id')->references('id')->on('companies')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropForeign(['payer_company_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::dropIfExists('companies');
    }
};
