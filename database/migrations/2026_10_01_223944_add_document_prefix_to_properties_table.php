<?php

use App\Actions\Property\GenerateDocumentPrefix;
use App\Models\Property;
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
        Schema::table('properties', function (Blueprint $table) {
            $table->string('document_prefix', 6)->nullable()->unique()->after('code');
        });

        Schema::create('property_document_prefix_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 6)->unique();
            $table->timestamps();

            $table->index('property_id');
        });

        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_document_prefix_check CHECK (document_prefix IS NULL OR document_prefix ~ '^[A-Z0-9]{3,6}$')");

        Property::query()->orderBy('id')->each(fn (Property $property) => app(GenerateDocumentPrefix::class)($property));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_document_prefix_histories');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('document_prefix');
        });
    }
};
