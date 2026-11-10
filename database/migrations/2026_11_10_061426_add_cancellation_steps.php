<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A paid cancellation charges by steps: "N or more days before arrival → charge". The free window becomes a 0 step and
 * the one charge becomes a step at 0 days, for the live policy and for saved online terms. Then `free_until_days` goes.
 * See docs/06-catalogues/06-room-revenue.md#policies.
 */
return new class extends Migration
{
    private const MODE_FIELDS_OLD = "(charge_mode = 'free' AND amount IS NULL AND percent IS NULL AND percent_basis IS NULL) OR (charge_mode = 'fixed_amount' AND amount IS NOT NULL AND percent IS NULL AND percent_basis IS NULL) OR (charge_mode = 'percentage' AND amount IS NULL AND percent IS NOT NULL AND percent_basis IS NOT NULL)";

    private const MODE_FIELDS_NEW = "(charge_mode = 'free' AND amount IS NULL AND percent IS NULL AND percent_basis IS NULL) OR (code = 'cancellation_revenue' AND charge_mode = 'fixed_amount' AND amount IS NULL AND percent IS NULL AND percent_basis IS NULL) OR (code = 'cancellation_revenue' AND charge_mode = 'percentage' AND amount IS NULL AND percent IS NULL AND percent_basis IS NOT NULL) OR (code <> 'cancellation_revenue' AND charge_mode = 'fixed_amount' AND amount IS NOT NULL AND percent IS NULL AND percent_basis IS NULL) OR (code <> 'cancellation_revenue' AND charge_mode = 'percentage' AND amount IS NULL AND percent IS NOT NULL AND percent_basis IS NOT NULL)";

    public function up(): void
    {
        Schema::create('room_revenue_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_revenue_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['room_revenue_policy_id', 'days_before']);
        });

        Schema::create('reservation_cancellation_term_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_cancellation_term_id')->constrained(indexName: 'cancellation_term_steps_term_id_foreign')->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['reservation_cancellation_term_id', 'days_before'], 'cancellation_term_steps_term_days_unique');
        });

        foreach (['room_revenue_policy_steps', 'reservation_cancellation_term_steps'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_days_before_check CHECK (days_before <= 365)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_charge_check CHECK ((amount IS NULL AND percent IS NOT NULL AND percent >= 0 AND percent <= 100) OR (percent IS NULL AND amount IS NOT NULL AND amount >= 0))");
        }

        DB::statement('ALTER TABLE room_revenue_policies DROP CONSTRAINT room_revenue_policies_mode_fields_check');

        $this->moveToSteps('room_revenue_policies', 'room_revenue_policy_steps', 'room_revenue_policy_id', true);
        $this->moveToSteps('reservation_cancellation_terms', 'reservation_cancellation_term_steps', 'reservation_cancellation_term_id', false);

        DB::statement('ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_mode_fields_check CHECK ('.self::MODE_FIELDS_NEW.')');
        DB::statement('ALTER TABLE room_revenue_policies DROP CONSTRAINT room_revenue_policies_free_until_days_check');

        Schema::table('room_revenue_policies', fn (Blueprint $table) => $table->dropColumn('free_until_days'));
        Schema::table('reservation_cancellation_terms', fn (Blueprint $table) => $table->dropColumn('free_until_days'));
    }

    public function down(): void
    {
        Schema::table('room_revenue_policies', fn (Blueprint $table) => $table->unsignedSmallInteger('free_until_days')->nullable());
        Schema::table('reservation_cancellation_terms', fn (Blueprint $table) => $table->unsignedSmallInteger('free_until_days')->nullable());

        DB::statement('ALTER TABLE room_revenue_policies DROP CONSTRAINT room_revenue_policies_mode_fields_check');

        $this->moveBack('room_revenue_policies', 'room_revenue_policy_steps', 'room_revenue_policy_id');
        $this->moveBack('reservation_cancellation_terms', 'reservation_cancellation_term_steps', 'reservation_cancellation_term_id');

        DB::statement('ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_mode_fields_check CHECK ('.self::MODE_FIELDS_OLD.')');
        DB::statement("ALTER TABLE room_revenue_policies ADD CONSTRAINT room_revenue_policies_free_until_days_check CHECK (free_until_days IS NULL OR code = 'cancellation_revenue')");

        Schema::dropIfExists('reservation_cancellation_term_steps');
        Schema::dropIfExists('room_revenue_policy_steps');
    }

    /**
     * A free window of N days becomes a 0 step at N, and the charge a step at 0 days. A window of 0 days is free at every
     * point, so it is one 0 step.
     */
    private function moveToSteps(string $parents, string $steps, string $foreignKey, bool $cancellationOnly): void
    {
        $rows = DB::table($parents)
            ->when($cancellationOnly, fn ($query) => $query->where('code', 'cancellation_revenue'))
            ->where('charge_mode', '<>', 'free')
            ->orderBy('id')
            ->get();
        $now = now();

        foreach ($rows as $row) {
            $isFixed = $row->charge_mode === 'fixed_amount';
            $zero = ['amount' => $isFixed ? '0.00' : null, 'percent' => $isFixed ? null : '0.00'];
            $charge = ['amount' => $isFixed ? $row->amount : null, 'percent' => $isFixed ? null : $row->percent];
            $base = ['property_id' => $row->property_id, $foreignKey => $row->id, 'created_at' => $now, 'updated_at' => $now];

            $insert = match (true) {
                $row->free_until_days === null => [[...$base, 'days_before' => 0, ...$charge]],
                (int) $row->free_until_days === 0 => [[...$base, 'days_before' => 0, ...$zero]],
                default => [[...$base, 'days_before' => (int) $row->free_until_days, ...$zero], [...$base, 'days_before' => 0, ...$charge]],
            };

            DB::table($steps)->insert($insert);
            DB::table($parents)->where('id', $row->id)->update(['amount' => null, 'percent' => null]);
        }
    }

    /**
     * Back to one charge and a free window: the window is the top run of 0 steps, the charge the first step that charges.
     */
    private function moveBack(string $parents, string $steps, string $foreignKey): void
    {
        $grouped = DB::table($steps)->orderByDesc('days_before')->get()->groupBy($foreignKey);

        foreach ($grouped as $parentId => $rows) {
            $freeUntil = null;
            $charge = null;

            foreach ($rows as $step) {
                if ((float) ($step->amount ?? $step->percent) !== 0.0) {
                    $charge = $step;
                    break;
                }

                $freeUntil = (int) $step->days_before;
            }

            $charge ??= $rows->last();

            DB::table($parents)->where('id', $parentId)->update([
                'free_until_days' => $freeUntil,
                'amount' => $charge->amount,
                'percent' => $charge->percent,
            ]);
        }
    }
};
