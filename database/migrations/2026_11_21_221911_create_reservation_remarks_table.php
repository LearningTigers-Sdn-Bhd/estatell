<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed remarks of a booking for the teams that act on them (`RemarkKind`: housekeeping, dietary, package, payment),
 * apart from the free text `reservations.notes`. One row per kind; an empty remark has no row.
 * See docs/12-reservations/16-remarks.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('body', 500);
            $table->timestamps();

            $table->unique(['reservation_id', 'kind']);
        });

        DB::statement("ALTER TABLE reservation_remarks ADD CONSTRAINT reservation_remarks_kind_check CHECK (kind IN ('housekeeping', 'dietary', 'package', 'payment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_remarks');
    }
};
