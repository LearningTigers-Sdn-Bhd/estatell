<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('api_key')->nullable();
            $table->string('company_subdomain')->nullable();
            $table->boolean('sandbox')->default(false);
            $table->string('guest_ledger_account_code')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('push_company_billing')->default(false);
            $table->date('company_billing_since')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_connections');
    }
};
