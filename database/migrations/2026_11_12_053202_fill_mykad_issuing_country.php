<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A MyKad is always Malaysian. MyKads saved from the guest Details field had no issuing country, so the Documents
     * tab showed none. Fill MY where it is empty. Other documents and set countries are not touched.
     */
    public function up(): void
    {
        DB::table('guest_identity_documents')
            ->where('type', 'mykad')
            ->whereNull('issuing_country_code')
            ->update(['issuing_country_code' => 'MY', 'updated_at' => now()]);
    }

    /**
     * Which rows were empty is not kept, so there is nothing to restore.
     */
    public function down(): void {}
};
