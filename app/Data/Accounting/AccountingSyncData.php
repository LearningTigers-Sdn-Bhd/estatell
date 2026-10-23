<?php

namespace App\Data\Accounting;

use App\Data\Shared\BadgeData;
use App\Enums\AccountingSyncStatus;
use App\Models\AccountingSync;
use Spatie\LaravelData\Data;

/**
 * One pushed (or failed) business date, for the sync history.
 */
class AccountingSyncData extends Data
{
    public function __construct(
        public int $id,
        public string $date,
        public BadgeData $status,
        public ?string $externalId,
        public ?string $error,
        public int $attempts,
        public ?string $pushedAt,
        public bool $canRetry,
    ) {}

    public static function fromModel(AccountingSync $sync): self
    {
        return new self(
            id: $sync->id,
            date: $sync->businessDate->date->toDateString(),
            status: BadgeData::fromAccountingSyncStatus($sync->status),
            externalId: $sync->external_id,
            error: $sync->error,
            attempts: $sync->attempts,
            pushedAt: $sync->pushed_at?->toIso8601String(),
            canRetry: $sync->status === AccountingSyncStatus::Failed,
        );
    }
}
