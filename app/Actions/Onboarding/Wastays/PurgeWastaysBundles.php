<?php

namespace App\Actions\Onboarding\Wastays;

use Illuminate\Filesystem\Filesystem;

/**
 * Deletes extracted wastays bundles from local storage. R2 is never touched.
 */
class PurgeWastaysBundles
{
    public function __construct(private Filesystem $files) {}

    /**
     * @param  string|null  $bundle  a folder inside imports/, absolute or relative to it; null purges everything
     * @return bool false when the folder is missing or outside imports/
     */
    public function __invoke(?string $bundle = null): bool
    {
        $root = storage_path(FetchWastaysBundle::IMPORTS_DIRECTORY);

        if ($bundle === null) {
            $this->files->deleteDirectory($root);

            return true;
        }

        $path = realpath(str_starts_with($bundle, '/') ? $bundle : $root.'/'.$bundle);
        $realRoot = realpath($root);

        if ($path === false || $realRoot === false || ! str_starts_with($path, $realRoot.'/')) {
            return false;
        }

        return $this->files->deleteDirectory($path);
    }
}
