<?php

namespace App\Actions\Accounting;

use App\Enums\AccountingSyncStatus;
use App\Enums\BusinessDateStatus;
use App\Features\AccountingIntegration;
use App\Models\AccountingSync;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\BuildDailyJournal;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

/**
 * Push the journal of one closed business date to the property's accounting software. Safe to run again: a Pushed or
 * Skipped date is returned as it is, and a Failed date is tried again. Returns null when the property has no enabled
 * connection, the feature is off, or the date is not Closed. An expected failure (a missing account, a refusal by the
 * provider) is saved on the row as Failed and does not throw. See docs/26-accounting/01-daily-journal.md.
 */
class PushDailyJournal
{
    public function __construct(private readonly BuildDailyJournal $buildDailyJournal) {}

    public function __invoke(Property $property, BusinessDate $businessDate): ?AccountingSync
    {
        $connection = $property->accountingConnection;

        if ($connection === null
            || ! $connection->enabled
            || $businessDate->property_id !== $property->id
            || $businessDate->status !== BusinessDateStatus::Closed
            || ! Feature::for($property)->active(AccountingIntegration::class)) {
            return null;
        }

        $sync = AccountingSync::query()->firstOrCreate(
            ['property_id' => $property->id, 'business_date_id' => $businessDate->id],
            ['provider' => $connection->provider, 'status' => AccountingSyncStatus::Pending],
        );

        $lock = Cache::lock("accounting-push:{$sync->id}", 120);

        if (! $lock->get()) {
            return $sync;
        }

        try {
            $sync->refresh();

            if (! $sync->status->canTransitionTo(AccountingSyncStatus::Pushed)) {
                return $sync;
            }

            $sync->forceFill(['attempts' => $sync->attempts + 1])->save();

            try {
                $journal = $this->buildDailyJournal->forDate($property, $connection, $businessDate);

                if ($journal === null) {
                    $sync->forceFill(['status' => AccountingSyncStatus::Skipped, 'error' => null])->save();

                    return $sync;
                }

                $externalId = $connection->provider->gateway($connection)->createJournal($journal);
            } catch (AccountingException $exception) {
                $sync->forceFill(['status' => AccountingSyncStatus::Failed, 'error' => $exception->getMessage()])->save();
                $connection->forceFill(['last_error' => $exception->getMessage()])->save();

                activity()
                    ->performedOn($sync)
                    ->event('accounting.journal-failed')
                    ->withProperties(['property_id' => $property->id, 'business_date' => $businessDate->date->toDateString(), 'error' => $exception->getMessage()])
                    ->log('Accounting journal failed');

                return $sync;
            }

            $sync->forceFill([
                'status' => AccountingSyncStatus::Pushed,
                'external_id' => $externalId,
                'error' => null,
                'pushed_at' => now(),
            ])->save();
            $connection->forceFill(['last_error' => null])->save();

            activity()
                ->performedOn($sync)
                ->event('accounting.journal-pushed')
                ->withProperties(['property_id' => $property->id, 'business_date' => $businessDate->date->toDateString(), 'external_id' => $externalId])
                ->log('Accounting journal pushed');

            return $sync;
        } finally {
            $lock->release();
        }
    }
}
