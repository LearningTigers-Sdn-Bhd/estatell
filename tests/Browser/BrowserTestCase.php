<?php

namespace Tests\Browser;

use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

/**
 * Browser tests that take the screenshots for the user docs. The demo properties are seeded once per process, at
 * the frozen date, and every test runs in a transaction on top of them.
 */
abstract class BrowserTestCase extends TestCase
{
    /**
     * Seeded by RefreshDatabase on its one `migrate:fresh` per process.
     *
     * @var class-string
     */
    protected $seeder = DemoSeeder::class;

    /**
     * Freeze the clock before RefreshDatabase seeds, so the demo's business date and bookings match the frozen day.
     */
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));

        parent::setUp();
    }
}
