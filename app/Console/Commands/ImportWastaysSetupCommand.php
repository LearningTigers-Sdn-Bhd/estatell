<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ImportWastaysSetup;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

#[Signature('wastays:import-setup {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--check-in= : Check-in time as HH:MM, not in the export} {--check-out= : Check-out time as HH:MM, not in the export} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Import the setup of a wastays hotel (rooms, rates, taxes, charges, payments) into a property that has not gone live, and set its document prefix to the hotel code')]
class ImportWastaysSetupCommand extends Command
{
    public function handle(ImportWastaysSetup $import): int
    {
        try {
            $target = WastaysImportTarget::resolve(
                (string) $this->argument('bundle'),
                (string) $this->argument('property'),
                (string) $this->option('actor'),
                alignPrefix: true,
            );

            $checkIn = $this->time('check-in');
            $checkOut = $this->time('check-out');
            $dryRun = (bool) $this->option('dry-run');
            $summary = $import($target, $dryRun, $checkIn, $checkOut);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Setup imported.');
        if ($summary->prefixChange !== null) {
            $this->line('Document prefix '.$summary->prefixChange);
        }

        $this->table(
            ['Part', 'Created', 'Already linked', 'Skipped'],
            collect($summary->parts)->map(fn (array $counts, string $part) => [$part, $counts['created'], $counts['linked'], $counts['skipped']])->values()->all(),
        );

        foreach ($summary->skippedNotes as $note) {
            $this->line(' - '.$note);
        }

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException when the option is not HH:MM, or only one of the two times is given
     */
    private function time(string $option): ?string
    {
        $value = $this->option($option);

        if ($value === null) {
            if ($this->option('check-in') !== null xor $this->option('check-out') !== null) {
                throw new InvalidArgumentException('Give both --check-in and --check-out, or neither.');
            }

            return null;
        }

        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException("--{$option} must be a time as HH:MM, for example 15:00.");
        }

        return $value;
    }
}
