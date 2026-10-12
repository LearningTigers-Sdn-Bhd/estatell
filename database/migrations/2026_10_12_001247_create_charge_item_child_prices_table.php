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
        Schema::create('charge_item_child_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_age_group_id')->constrained()->cascadeOnDelete();
            $table->string('pricing', 10);
            $table->decimal('value', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['charge_item_id', 'property_age_group_id']);
            $table->index('property_age_group_id');
        });

        DB::statement("ALTER TABLE charge_item_child_prices ADD CONSTRAINT charge_item_child_prices_pricing_check CHECK (
            (pricing = 'free' AND value IS NULL)
            OR (pricing = 'percent' AND value > 0 AND value <= 100)
            OR (pricing = 'fixed' AND value >= 0)
        )");

        Schema::table('charge_items', function (Blueprint $table) {
            $table->boolean('needs_child_price_review')->default(false)->after('allow_override');
        });

        Schema::table('unit_add_ons', function (Blueprint $table) {
            $table->foreignId('property_age_group_id')->nullable()->after('service_date')->constrained()->nullOnDelete();
        });

        $this->backfillChildPrices();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_add_ons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_age_group_id');
        });

        Schema::table('charge_items', function (Blueprint $table) {
            $table->dropColumn('needs_child_price_review');
        });

        Schema::dropIfExists('charge_item_child_prices');
    }

    /**
     * Migration-local snapshot. A per-guest item with a default amount charges children as adults (Percent 100).
     * An item priced when charging has no amount to take a percentage of, so its groups are Free and it is
     * flagged "Check child prices". Runtime defaults are owned by the charge item Actions.
     */
    private function backfillChildPrices(): void
    {
        $now = now();

        DB::table('charge_items')
            ->whereIn('charging_unit', ['per_guest', 'per_guest_night'])
            ->select('id', 'property_id', 'default_amount')
            ->orderBy('id')
            ->each(function (object $item) use ($now): void {
                $groupIds = DB::table('property_age_groups')->where('property_id', $item->property_id)->pluck('id');

                if ($groupIds->isEmpty()) {
                    return;
                }

                $priced = $item->default_amount !== null;

                DB::table('charge_item_child_prices')->insert($groupIds->map(fn (int $groupId): array => [
                    'charge_item_id' => $item->id,
                    'property_age_group_id' => $groupId,
                    'pricing' => $priced ? 'percent' : 'free',
                    'value' => $priced ? 100 : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                if (! $priced) {
                    DB::table('charge_items')->where('id', $item->id)->update(['needs_child_price_review' => true]);
                }
            });
    }
};
