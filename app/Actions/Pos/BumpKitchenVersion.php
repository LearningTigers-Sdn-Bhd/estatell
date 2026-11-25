<?php

namespace App\Actions\Pos;

use App\Models\PosKitchenStation;
use App\Services\Pos\KitchenDisplay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Give a station and its outlet's pickup board a new version, so their screens reload on the next poll. Call it after any
 * change to a station's lines: fire, status, bump, recall or void. A station that is null (a line with no station) does nothing.
 */
class BumpKitchenVersion
{
    public function __invoke(?PosKitchenStation $station): void
    {
        if ($station === null) {
            return;
        }

        Cache::forever(KitchenDisplay::stationVersionKey($station->id), Str::random(12));
        Cache::forever(KitchenDisplay::outletVersionKey($station->pos_outlet_id), Str::random(12));
    }
}
