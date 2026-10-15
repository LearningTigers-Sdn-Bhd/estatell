<?php

namespace App\Data\Dashboard;

use App\Data\NightAudit\ReadinessActionData;
use Spatie\LaravelData\Data;

class DashboardTodoRowData extends Data
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $label,
        public string $guest,
        public string $reference,
        public string $detail,
        public ?string $time,
        public ReadinessActionData $action,
    ) {}
}
