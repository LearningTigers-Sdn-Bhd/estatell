<?php

namespace App\Http\Controllers\Guests;

use App\Http\Controllers\Controller;
use App\Services\Guests\MykadReaderRelease;
use Illuminate\Http\RedirectResponse;

class MykadReaderDownloadController extends Controller
{
    /**
     * Public, because the user docs link here and the installers are public on GitHub.
     *
     * @param  'windows'|'mac'  $platform
     */
    public function __invoke(string $platform, MykadReaderRelease $release): RedirectResponse
    {
        return redirect()->away($release->downloadUrl($platform));
    }
}
