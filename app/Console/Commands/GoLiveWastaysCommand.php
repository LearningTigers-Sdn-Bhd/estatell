<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\ApproveGoLive;
use App\Actions\Onboarding\GoLive;
use App\Actions\Onboarding\RequestGoLive;
use App\Data\Onboarding\GoLiveCheckData;
use App\Enums\PropertyStatus;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

#[Signature('wastays:go-live {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--opening-date= : Opening date as Y-m-d, default the first wastays business date} {--dry-run : Print the readiness checks and the opening date, change nothing}')]
#[Description('Take the property live with the first wastays business date as its opening date, so the replay can run')]
class GoLiveWastaysCommand extends Command
{
    public function handle(GoLiveReadiness $readiness, GoLive $goLive, RequestGoLive $requestGoLive, ApproveGoLive $approveGoLive, PlatformSettings $platformSettings): int
    {
        try {
            $target = WastaysImportTarget::resolve(
                (string) $this->argument('bundle'),
                (string) $this->argument('property'),
                (string) $this->option('actor'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $property = $target->property;
        $given = $this->option('opening-date');

        if ($given !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) || ! checkdate((int) substr($given, 5, 2), (int) substr($given, 8, 2), (int) substr($given, 0, 4)))) {
            $this->error('--opening-date must be a date as Y-m-d, for example 2026-10-23.');

            return self::FAILURE;
        }

        $openingDate = CarbonImmutable::parse($given ?? $target->bundle->manifest->firstAuditDate);

        $this->line("Opening date {$openingDate->toDateString()} ".($given === null ? '(the first wastays business date).' : '(given).'));
        $this->table(
            ['Step', 'Done'],
            collect($readiness->checks($property))->map(fn (GoLiveCheckData $check): array => [$check->label, $check->done ? 'yes' : 'NO'])->all(),
        );

        if (! $readiness->isReady($property)) {
            $this->error('The property is not ready to go live. Finish the steps marked NO.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $live = $this->quietly(function () use ($property, $target, $openingDate, $goLive, $requestGoLive, $approveGoLive, $platformSettings): bool {
            if ($property->status === PropertyStatus::Onboarding && $platformSettings->current()->require_go_live_approval) {
                if (! $requestGoLive($target->actor, $property)) {
                    return false;
                }

                $property->refresh();
            }

            return $property->status === PropertyStatus::PendingApproval
                ? $approveGoLive($target->actor, $property, $openingDate)
                : $goLive($target->actor, $property, $openingDate);
        });

        if (! $live) {
            $this->error('The property could not go live.');

            return self::FAILURE;
        }

        $this->info("{$property->code} is live from {$openingDate->toDateString()}.");

        return self::SUCCESS;
    }

    /**
     * Run with no queue and mail kept in memory, so the request and approval send no message to staff or the owner.
     *
     * @param  Closure(): bool  $run
     */
    private function quietly(Closure $run): bool
    {
        $before = [config('queue.default'), config('mail.default')];

        config(['queue.connections.null' => ['driver' => 'null'], 'queue.default' => 'null', 'mail.default' => 'array']);
        Mail::purge();

        try {
            return $run();
        } finally {
            config(['queue.default' => $before[0], 'mail.default' => $before[1]]);
            Mail::purge();
        }
    }
}
