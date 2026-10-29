<?php

namespace Tests\Browser;

use Database\Seeders\BookingScenariosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Seeder;

/**
 * The data for the docs screenshots: the demo properties, and the booking scenarios property for the stays the demo
 * does not have (guests in house since an earlier day, a departure today, a group in house).
 */
class DocsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([DemoSeeder::class, BookingScenariosSeeder::class]);
    }
}
