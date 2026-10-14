<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Freeze the clock so tests with fixed dates (arrivals on 2026-10-20 and so on) do not depend on the real
     * date. A test that needs another moment travels there itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
