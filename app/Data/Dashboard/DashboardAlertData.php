<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * The night audit alert at the top of the dashboard: one sentence about what is wrong.
 */
class DashboardAlertData extends Data
{
    public function __construct(
        public string $message,
    ) {}
}
