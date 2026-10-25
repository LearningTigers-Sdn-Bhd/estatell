<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\BillWastaysCompanies;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('wastays:bill-companies {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Bring the company invoices and payments of a wastays bundle into the city ledger after a replay, instead of importing the balances')]
class BillWastaysCompaniesCommand extends Command
{
    public function handle(BillWastaysCompanies $bill): int
    {
        try {
            $target = WastaysImportTarget::resolveForReplay(
                (string) $this->argument('bundle'),
                (string) $this->argument('property'),
                (string) $this->option('actor'),
            );

            $dryRun = (bool) $this->option('dry-run');
            $summary = $bill($target, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $problems = $summary->counts['problems'] ?? 0;
        $this->info($dryRun ? 'Dry run: nothing was written.' : ($problems > 0 ? 'Problems found: nothing was written.' : 'Company billing imported.'));
        $this->table(['Count', 'Rows'], collect($summary->counts)->map(fn (int $count, string $label) => [$label, $count])->values()->all());

        foreach ($summary->notes as $note) {
            $this->line(' - '.$note);
        }

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
