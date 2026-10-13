<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE TABLE property_code_counters (prefix char(2) PRIMARY KEY, last_number bigint NOT NULL)');

        DB::statement('ALTER TABLE properties ALTER COLUMN code DROP DEFAULT');
        DB::statement("ALTER TABLE properties ALTER COLUMN code TYPE varchar(16) USING 'ES' || code");

        DB::statement("INSERT INTO property_code_counters (prefix, last_number) SELECT 'ES', max(substr(code, 3)::bigint) FROM properties HAVING count(*) > 0");

        DB::statement('DROP SEQUENCE property_code_seq');

        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_code_check CHECK (code ~ '^[A-Z]{2}[0-9]{5,}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE properties DROP CONSTRAINT properties_code_check');

        DB::statement('ALTER TABLE properties ALTER COLUMN code TYPE bigint USING substr(code, 3)::bigint');

        $next = ((int) DB::table('properties')->max('code')) + 1;
        $start = DB::table('properties')->exists() ? $next : 10101;

        DB::statement("CREATE SEQUENCE property_code_seq START WITH {$start}");
        DB::statement("ALTER TABLE properties ALTER COLUMN code SET DEFAULT nextval('property_code_seq')");
        DB::statement('ALTER SEQUENCE property_code_seq OWNED BY properties.code');

        DB::statement('DROP TABLE property_code_counters');
    }
};
