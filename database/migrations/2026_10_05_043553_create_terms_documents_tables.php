<?php

use App\Actions\Registration\SeedRegistrationTerms;
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
        Schema::create('terms_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX terms_documents_one_default_per_property ON terms_documents (property_id) WHERE is_default');

        Schema::create('registration_card_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terms_document_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->unsignedInteger('version');
            $table->text('body');
            $table->string('preset', 30)->nullable();
            $table->unsignedSmallInteger('preset_version')->nullable();
            $table->timestampTz('published_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['terms_document_id', 'locale', 'version']);
        });

        DB::statement("ALTER TABLE registration_card_terms ADD CONSTRAINT registration_card_terms_locale_check CHECK (locale IN ('en', 'ms'))");

        Schema::table('room_types', function (Blueprint $table) {
            $table->foreign('terms_document_id')->references('id')->on('terms_documents')->nullOnDelete();
        });

        $this->backfillDefaults();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropForeign(['terms_document_id']);
        });

        Schema::dropIfExists('registration_card_terms');
        Schema::dropIfExists('terms_documents');
    }

    /**
     * Give every existing property that has a type its default terms document.
     */
    private function backfillDefaults(): void
    {
        Property::query()->whereNotNull('type')->orderBy('id')->each(fn (Property $property) => app(SeedRegistrationTerms::class)($property));
    }
};
