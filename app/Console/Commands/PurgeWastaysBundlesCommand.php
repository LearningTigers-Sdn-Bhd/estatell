<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\Wastays\PurgeWastaysBundles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wastays:purge {bundle? : Bundle folder inside imports/} {--all : Delete every extracted bundle}')]
#[Description('Delete extracted wastays bundles from local storage (R2 is not touched)')]
class PurgeWastaysBundlesCommand extends Command
{
    public function handle(PurgeWastaysBundles $purge): int
    {
        $bundle = $this->argument('bundle');

        if (! $this->option('all') && $bundle === null) {
            $this->error('Give a bundle folder or use --all.');

            return self::FAILURE;
        }

        if (! $purge($this->option('all') ? null : $bundle)) {
            $this->error('That bundle was not found inside imports/.');

            return self::FAILURE;
        }

        $this->info('Purged. R2 was not touched.');

        return self::SUCCESS;
    }
}
