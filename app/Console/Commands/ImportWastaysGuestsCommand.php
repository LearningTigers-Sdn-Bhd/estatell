<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ImportWastaysGuests;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('wastays:import-guests {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Import the guests of a wastays bundle into a property that has not gone live')]
class ImportWastaysGuestsCommand extends Command
{
    public function handle(ImportWastaysGuests $import): int
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

        $dryRun = (bool) $this->option('dry-run');
        $counts = $import($target, $dryRun);

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Guests imported.');
        $this->table(['Count', 'Guests'], collect($counts)->map(fn (int $count, string $label) => [$label, $count])->values()->all());

        return self::SUCCESS;
    }
}
