<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\FetchWastaysBundle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wastays:fetch {key : Key of the zip on the r2 disk} {--sha256= : Expected SHA-256 of the zip}')]
#[Description('Download a wastays export bundle from R2, unzip it and check it against its manifest')]
class FetchWastaysBundleCommand extends Command
{
    public function handle(FetchWastaysBundle $fetch): int
    {
        $result = $fetch((string) $this->argument('key'), $this->option('sha256') ?: null);

        if (! $result->succeeded()) {
            $this->error('The bundle was refused and nothing was kept:');
            foreach ($result->problems as $problem) {
                $this->line(' - '.$problem);
            }

            return self::FAILURE;
        }

        $manifest = $result->bundle->manifest;

        $this->info('Bundle: '.$result->bundle->path);
        $this->line("Hotel: {$manifest->hotelName} ({$manifest->hotelCode}, wastays id {$manifest->hotelId})");
        $this->line("Audit dates: {$manifest->firstAuditDate} to {$manifest->lastAuditDate}");
        $this->table(
            ['File', 'Rows'],
            collect($manifest->files)->map(fn (array $file, string $name) => [$name, $file['rows']])->values()->all(),
        );

        return self::SUCCESS;
    }
}
