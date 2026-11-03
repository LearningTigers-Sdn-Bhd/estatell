<?php

namespace App\Services\Guests;

class MykadReaderRelease
{
    private const RELEASES_URL = 'https://github.com/LearningTigers-Sdn-Bhd/estatell-mykad-reader/releases/download';

    /**
     * The universal installer of the MyKad Reader version in `estatell.mykad_reader_version`.
     *
     * @param  'windows'|'mac'  $platform
     */
    public function downloadUrl(string $platform): string
    {
        $version = config('estatell.mykad_reader_version');

        $filename = match ($platform) {
            'windows' => "estatell-mykad-reader-setup-v{$version}.exe",
            'mac' => "estatell-mykad-reader-v{$version}.pkg",
        };

        return self::RELEASES_URL."/v{$version}/{$filename}";
    }
}
