<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ImportWastaysSetup;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('wastays:import-setup {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Import the setup of a wastays hotel (rooms, rates, taxes, charges, payments) into a property that has not gone live')]
class ImportWastaysSetupCommand extends Command
{
    public function handle(ImportWastaysSetup $import): int
    {
        try {
            $target = WastaysImportTarget::resolve(
                (string) $this->argument('bundle'),
                (string) $this->argument('property'),
                (string) $this->option('actor'),
            );

            $dryRun = (bool) $this->option('dry-run');
            $summary = $import($target, $dryRun);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Setup imported.');
        $this->table(
            ['Part', 'Created', 'Already linked', 'Skipped'],
            collect($summary->parts)->map(fn (array $counts, string $part) => [$part, $counts['created'], $counts['linked'], $counts['skipped']])->values()->all(),
        );

        foreach ($summary->skippedNotes as $note) {
            $this->line(' - '.$note);
        }

        return self::SUCCESS;
    }
}
