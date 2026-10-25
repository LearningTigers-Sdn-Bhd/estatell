<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ImportWastaysStays;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('wastays:import-stays {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Import the stays of a wastays bundle as Old stays and future bookings, for a hotel whose history stays in wastays')]
class ImportWastaysStaysCommand extends Command
{
    public function handle(ImportWastaysStays $import): int
    {
        try {
            $target = WastaysImportTarget::resolveForReplay(
                (string) $this->argument('bundle'),
                (string) $this->argument('property'),
                (string) $this->option('actor'),
            );

            $dryRun = (bool) $this->option('dry-run');
            $summary = $import($target, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Stays imported.');
        $this->table(['Count', 'Bookings'], collect($summary->counts)->map(fn (int $count, string $label) => [$label, $count])->values()->all());

        foreach ($summary->notes as $note) {
            $this->line(' - '.$note);
        }

        return ($summary->counts['bookings that could not be imported'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
