<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:create-test', function () {
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    $database = (string) ($phpunit->xpath('//env[@name="DB_DATABASE"]')[0]['value'] ?? '');

    if (! preg_match('/^[a-z0-9_]+$/', $database)) {
        $this->error("Invalid DB_DATABASE in phpunit.xml: [{$database}]");

        return 1;
    }

    config(['database.connections.pgsql_maintenance' => [
        ...config('database.connections.pgsql'),
        'url' => null,
        'database' => 'postgres',
    ]]);
    $connection = DB::connection('pgsql_maintenance');

    if ($connection->scalar('select 1 from pg_database where datname = ?', [$database])) {
        $this->info("Test database [{$database}] already exists.");

        return 0;
    }

    $connection->statement("create database \"{$database}\"");
    $this->info("Created test database [{$database}].");

    return 0;
})->purpose('Create the test database named in phpunit.xml if it does not exist');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
