<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\ApproveGoLive;
use App\Actions\Onboarding\GoLive;
use App\Data\Onboarding\GoLiveCheckData;
use App\Enums\PropertyStatus;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('wastays:go-live {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Print the readiness checks and the opening date, change nothing}')]
#[Description('Take the property live with the first wastays business date as its opening date, so the replay can run')]
class GoLiveWastaysCommand extends Command
{
    public function handle(GoLiveReadiness $readiness, GoLive $goLive, ApproveGoLive $approveGoLive): int
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
        $openingDate = CarbonImmutable::parse($target->bundle->manifest->firstAuditDate);

        $this->line("Opening date {$openingDate->toDateString()} (the first wastays business date).");
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

        $live = $property->status === PropertyStatus::PendingApproval
            ? $approveGoLive($target->actor, $property, $openingDate)
            : $goLive($target->actor, $property, $openingDate);

        if (! $live) {
            $this->error('The property could not go live. If the platform asks for approval, the owner must request go-live first.');

            return self::FAILURE;
        }

        $this->info("{$property->code} is live from {$openingDate->toDateString()}.");

        return self::SUCCESS;
    }
}
