<?php

use App\Services\Guests\GuestName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->renamePlaceholders('Anonymized guest', 'Archived User');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->renamePlaceholders('Archived User', 'Anonymized guest');
    }

    private function renamePlaceholders(string $from, string $to): void
    {
        $guestName = app(GuestName::class);

        foreach (DB::table('guests')->whereNotNull('anonymized_at')->select('id', 'full_name')->lazyById() as $guest) {
            if ($guest->full_name !== "{$from} #{$guest->id}") {
                continue;
            }

            $name = "{$to} #{$guest->id}";

            DB::table('guests')->where('id', $guest->id)->whereNotNull('anonymized_at')->where('full_name', $guest->full_name)->update([
                'full_name' => $name,
                'name_key' => $guestName->key($name),
            ]);
        }
    }
};
