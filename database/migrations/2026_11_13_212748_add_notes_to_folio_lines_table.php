<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A staff note written with the line and never changed: the note of a credit move, on both
     * lines of each pair and their reversals. Existing credit moves take the note of their `folio.credit_moved` log entry when exactly one
     * pair matches its source folio, target folio, posting date and amount. The ledger trigger is off only for that.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->string('notes', 255)->nullable()->after('description');
        });

        $entries = DB::table('activity_log')->where('event', 'folio.credit_moved')->orderBy('id')->get(['properties']);

        if ($entries->isEmpty()) {
            return;
        }

        DB::statement('ALTER TABLE folio_lines DISABLE TRIGGER folio_lines_immutable');

        foreach ($entries as $entry) {
            $properties = json_decode((string) $entry->properties, true);
            $note = is_array($properties) ? ($properties['note'] ?? null) : null;

            if (! is_string($note) || trim($note) === '') {
                continue;
            }

            foreach ($properties['moves'] ?? [] as $move) {
                $groups = DB::table('folio_lines')
                    ->where('folio_id', $properties['folio_id'] ?? 0)
                    ->where('posting_date', $properties['posting_date'] ?? null)
                    ->where('amount', $move['amount'] ?? null)
                    ->where('idempotency_key', 'like', 'credit-move:%:'.($move['folio_id'] ?? 0).':out')
                    ->whereNull('notes')
                    ->pluck('transfer_group_id');

                if ($groups->count() === 1) {
                    DB::table('folio_lines')->where('transfer_group_id', $groups->first())->update(['notes' => $note]);
                }
            }
        }

        DB::statement('ALTER TABLE folio_lines ENABLE TRIGGER folio_lines_immutable');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
