<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\ReplayStopped;
use App\Actions\Onboarding\Wastays\ReplayWastaysDay;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\Wastays\WastaysDay;
use App\Services\Onboarding\Wastays\WastaysDayCompare;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Brick\Math\BigDecimal;
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
        'check-out moved to the departure day',
        'room type changes left out',
        'room edits at check-in left out',
        'charges waiting for check-in',
        'bookings over the room type capacity',
        'charges moved to the check-in day',
        'late check-ins moved to the arrival day',
        'stays moved earlier after check-in left out',
        'check-ins left for a later move to an earlier arrival',
        'waiting charges reversed before check-in',
        'folio movements left in place',
        'stay dates left: the room is not free',
        'stay dates left: the new arrival is in a closed night',
        'charges posted on a code that was turned off',
        'group bookings checked out with a balance',
        'advances taken as settlements, the company pays the rooms',
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
        $acceptedDifferences = [];
        $notes = [];
        $carried = array_fill_keys(WastaysDayCompare::FIGURES, BigDecimal::zero());
        $status = self::SUCCESS;

        // A dry run holds every day in one transaction to roll it all back. A real run commits each day on its own, so
        // a long run does not pile up locks and a crash keeps the days before it.
        if ($dryRun) {
            DB::beginTransaction();
        }

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
                $settles = ! $accepted && $differences !== [] && $this->settlesCarried($carried, $differences);
                $result = match (true) {
                    $wastays === null => 'no wastays figures',
                    $differences === [] => 'matches',
                    $accepted => 'differs (accepted)',
                    $settles => 'differs (settles an earlier day)',
                    default => 'DIFFERS',
                };
                $rows[] = [$date, $result, $this->counts($summary->counts)];

                foreach ($summary->notes as $note) {
                    $notes[] = [$date, $note];
                }

                if ($accepted || $settles) {
                    foreach ($differences as $difference) {
                        $carried[$difference['figure']] = $carried[$difference['figure']]->plus(BigDecimal::of($difference['estatell'])->minus($difference['wastays']));
                    }
                }

                foreach ($accepted ? $differences : [] as $difference) {
                    $acceptedDifferences[] = [$date, ...array_values($difference)];
                }

                if ($differences !== [] && ! $accepted && ! $settles) {
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

        if ($notes !== []) {
            $this->line('Notes:');
            $this->table(['Date', 'Note'], $notes);
        }

        if ($acceptedDifferences !== []) {
            $this->warn('Accepted days that still differ. Check that each difference comes from the accepted case:');
            $this->table(['Date', 'Figure', 'wastays', 'estatell'], $acceptedDifferences);
        }

        $owed = array_filter($carried, fn (BigDecimal $amount): bool => ! $amount->isZero());

        if ($owed !== [] && $status === self::SUCCESS) {
            $this->warn('Accepted differences that no later day settled. estatell is ahead of wastays by:');
            $this->table(['Figure', 'estatell minus wastays'], array_map(fn (string $figure, BigDecimal $amount): array => [$figure, (string) $amount->toScale(2)], array_keys($owed), $owed));
        }

        if ($dryRun) {
            DB::rollBack();
        }
        $this->info($dryRun ? 'Dry run: nothing was written.' : count($rows).' day(s) replayed.');

        return $status;
    }

    /**
     * Whether every difference of a day is wastays posting what estatell already posted on an earlier accepted day: the
     * figure is owed by estatell (it is ahead of wastays) and the day brings wastays level again, by no more than is owed.
     * A late-recorded stay is posted on its arrival day in estatell and on the day it was typed in wastays.
     *
     * @param  array<string, BigDecimal>  $carried  estatell minus wastays, per figure, from the accepted days so far
     * @param  list<array{figure: string, wastays: string, estatell: string}>  $differences
     */
    private function settlesCarried(array $carried, array $differences): bool
    {
        foreach ($differences as $difference) {
            $delta = BigDecimal::of($difference['estatell'])->minus($difference['wastays']);
            $owed = $carried[$difference['figure']];

            if ($owed->isZero() || $delta->getSign() === $owed->getSign() || $delta->abs()->isGreaterThan($owed->abs())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts): string
    {
        return implode(', ', array_map(fn (string $label, int $count): string => "{$label}: {$count}", array_keys($counts), $counts));
    }
}
