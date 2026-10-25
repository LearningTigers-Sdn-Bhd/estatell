<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ImportWastaysBalances;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('wastays:import-balances {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Import what companies still owe and the credit they paid in advance from a wastays bundle, after go-live')]
class ImportWastaysBalancesCommand extends Command
{
    public function handle(ImportWastaysBalances $import): int
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

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Balances imported.');
        $this->table(['Count', 'Rows'], collect($summary->counts)->map(fn (int $count, string $label) => [$label, $count])->values()->all());

        foreach ($summary->notes as $note) {
            $this->line(' - '.$note);
        }

        return ($summary->counts['rows that could not be imported'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
