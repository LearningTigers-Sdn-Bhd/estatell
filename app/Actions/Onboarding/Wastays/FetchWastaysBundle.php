<?php

namespace App\Actions\Onboarding\Wastays;

use App\Services\Onboarding\Wastays\WastaysBundle;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ZipArchive;

/**
 * Downloads a wastays export zip from the r2 disk, checks it and unzips it into storage/app/private/imports/{hotel-slug}/{date}/.
 * A bundle that does not match its manifest is deleted again.
 */
class FetchWastaysBundle
{
    public const IMPORTS_DIRECTORY = 'app/private/imports';

    public function __construct(private Filesystem $files) {}

    public function __invoke(string $key, ?string $zipSha256 = null): WastaysFetchResult
    {
        $disk = Storage::disk('r2');

        if (! $disk->exists($key)) {
            return new WastaysFetchResult(null, ["{$key} does not exist on the r2 disk."]);
        }

        $expectedSha = $zipSha256 ?? $this->sha256Beside($key);
        $target = storage_path(self::IMPORTS_DIRECTORY).'/'.basename(dirname($key)).'/'.basename($key, '.zip');
        $zipPath = $target.'.zip.part';

        $this->files->ensureDirectoryExists(dirname($target));

        try {
            $this->download($key, $zipPath);

            if ($expectedSha !== null && ! hash_equals(strtolower($expectedSha), hash_file('sha256', $zipPath))) {
                return new WastaysFetchResult(null, ['The zip SHA-256 does not match the expected value.']);
            }

            $this->files->deleteDirectory($target);
            $problems = $this->extract($zipPath, $target);

            if ($problems === []) {
                try {
                    $bundle = WastaysBundle::open($target);
                    $problems = $bundle->problems();
                } catch (InvalidArgumentException $e) {
                    $problems = [$e->getMessage()];
                }
            }

            if ($problems !== []) {
                $this->files->deleteDirectory($target);

                return new WastaysFetchResult(null, $problems);
            }

            return new WastaysFetchResult($bundle);
        } finally {
            $this->files->delete($zipPath);
        }
    }

    private function sha256Beside(string $key): ?string
    {
        $disk = Storage::disk('r2');

        if (! $disk->exists($key.'.sha256')) {
            return null;
        }

        $first = preg_split('/\s+/', trim((string) $disk->get($key.'.sha256')))[0] ?? '';

        return $first === '' ? null : $first;
    }

    private function download(string $key, string $zipPath): void
    {
        $stream = Storage::disk('r2')->readStream($key);
        $out = fopen($zipPath, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
    }

    /** @return list<string> */
    private function extract(string $zipPath, string $target): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return ['The file is not a readable zip.'];
        }

        $this->files->ensureDirectoryExists($target);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name) || $name === '.' || $name === '..') {
                $zip->close();

                return ["The zip holds an unexpected entry: {$name}."];
            }

            file_put_contents($target.'/'.$name, $zip->getFromIndex($i));
        }

        $zip->close();

        return [];
    }
}
