<?php

namespace App\Jobs;

use App\Actions\Accounting\PushDailyJournal;
use App\Models\BusinessDate;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushDailyJournalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * An expected failure is saved on the sync row, so only an unexpected error is retried.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public int $propertyId, public int $businessDateId) {}

    public function uniqueId(): string
    {
        return "{$this->propertyId}:{$this->businessDateId}";
    }

    /**
     * Push one closed date with `PushDailyJournal`. It runs after the audit ended, so an error here never changes the audit.
     */
    public function handle(PushDailyJournal $pushDailyJournal): void
    {
        $property = Property::query()->find($this->propertyId);
        $businessDate = BusinessDate::query()->find($this->businessDateId);

        if ($property === null || $businessDate === null) {
            return;
        }

        $pushDailyJournal($property, $businessDate);
    }
}
