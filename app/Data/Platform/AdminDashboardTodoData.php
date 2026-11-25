<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

class AdminDashboardTodoData extends Data
{
    /**
     * @param  list<AdminDashboardTodoRowData>  $rows
     */
    public function __construct(
        public int $total,
        public array $rows,
    ) {}
}
