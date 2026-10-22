<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ReplayStopped;
use App\Actions\Onboarding\Wastays\ReplayWastaysDay;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysDayCompare;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Signature('wastays:replay {bundle : Path of the extracted bundle that wastays:fetch printed} {property : Code of the estatell property} {--actor= : Email of an admin user, shown in the activity log} {--until= : Last wastays business date to replay (Y-m-d), default the last night audit} {--dry-run : Run everything, roll back and print the summary}')]
#[Description('Replay the business dates of a wastays bundle through the estatell Actions and compare each day with its wastays night audit')]
class ReplayWastaysCommand extends Command
{
    /** Counts that stand for a difference the docs accept. A day that shows one is listed, not stopped on. */
    private const array ACCEPTED = [
        'check-ins before the arrival date moved',
        'check-in moved to the arrival day',
        'same-day leavers kept as one night',
        'check-out moved to the departure day',
        'room type changes left out',
        'room edits at check-in left out',
    ];

    public function handle(ReplayWastaysDay $replay, WastaysDayCompare $compare, CurrentBusinessDate $currentBusinessDate): int
    {
        try {
            $target = WastaysImportTarget::resolveForReplay((string) $this->argument('bundle'), (string) $this->argument('property'), (string) $this->option('actor'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $day = new WastaysDay($target->bundle);
        $dryRun = (bool) $this->option('dry-run');
        $until = (string) ($this->option('until') ?: $target->bundle->manifest->lastAuditDate);
        $start = $currentBusinessDate->date($target->property)?->toDateString();
        $dates = array_values(array_filter($day->dates(), fn (string $date): bool => $date >= (string) $start && $date <= $until && $day->nightAudit($date) !== null));
        $rows = [];
        $status = self::SUCCESS;

        DB::beginTransaction();

        try {
            foreach ($dates as $date) {
                DB::beginTransaction();

                try {
                    $summary = $replay($target, $day, $date);
                } catch (ReplayStopped $e) {
                    DB::rollBack();

                    throw $e;
                }

                if (isset($summary->counts['day already replayed'])) {
                    DB::commit();
                    $rows[] = [$date, 'already replayed', ''];

                    continue;
                }

                $wastays = $compare->wastays($target->bundle, $day, $date);
                $differences = $wastays === null ? [] : $compare->differences($wastays, $compare->estatell($target->property, $date));
                $accepted = array_intersect(self::ACCEPTED, array_keys($summary->counts)) !== [];
                $result = match (true) {
                    $wastays === null => 'no wastays figures',
                    $differences === [] => 'matches',
                    $accepted => 'differs (accepted)',
                    default => 'DIFFERS',
                };
                $rows[] = [$date, $result, $this->counts($summary->counts)];

                if ($differences !== [] && ! $accepted) {
                    DB::rollBack();
                    $this->table(['Date', 'Result', 'Counts'], $rows);
                    $this->error("Stopped at {$date}: the day does not match its wastays night audit. Nothing of that day was kept.");
                    $this->table(['Figure', 'wastays', 'estatell'], array_map(fn (array $row): array => array_values($row), $differences));
                    $status = self::FAILURE;

                    break;
                }

                DB::commit();
            }

            if ($status === self::SUCCESS) {
                $this->table(['Date', 'Result', 'Counts'], $rows);
            }
        } catch (ReplayStopped $e) {
            $this->table(['Date', 'Result', 'Counts'], $rows);
            $this->error('Stopped: '.$e->getMessage());
            $status = self::FAILURE;
        }

        $dryRun ? DB::rollBack() : DB::commit();
        $this->info($dryRun ? 'Dry run: nothing was written.' : count($rows).' day(s) replayed.');

        return $status;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts): string
    {
        return implode(', ', array_map(fn (string $label, int $count): string => "{$label}: {$count}", array_keys($counts), $counts));
    }
}
