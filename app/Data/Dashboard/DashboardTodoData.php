<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

class DashboardTodoData extends Data
{
    /** @param list<DashboardTodoRowData> $rows */
    public function __construct(public array $rows, public int $total, public string $date) {}
}
