<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 60);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        Schema::table('amenities', function (Blueprint $table) {
            $table->foreignId('amenity_category_id')->nullable()->after('type')->constrained('amenity_categories')->restrictOnDelete();
        });

        $groups = DB::table('amenities')
            ->select('type', 'category')
            ->selectRaw('min(position) as first_position')
            ->groupBy('type', 'category')
            ->orderBy('first_position')
            ->get();

        foreach ($groups as $index => $group) {
            $categoryId = DB::table('amenity_categories')->insertGetId([
                'type' => $group->type,
                'name' => $group->category,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('amenities')
                ->where('type', $group->type)
                ->where('category', $group->category)
                ->update(['amenity_category_id' => $categoryId]);
        }

        Schema::table('amenities', function (Blueprint $table) {
            $table->foreignId('amenity_category_id')->nullable(false)->change();
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->string('category', 60)->nullable();
        });

        DB::statement('UPDATE amenities SET category = amenity_categories.name FROM amenity_categories WHERE amenity_categories.id = amenities.amenity_category_id');

        Schema::table('amenities', function (Blueprint $table) {
            $table->string('category', 60)->nullable(false)->change();
            $table->dropConstrainedForeignId('amenity_category_id');
        });

        Schema::dropIfExists('amenity_categories');
    }
};
