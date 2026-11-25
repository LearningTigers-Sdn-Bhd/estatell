<?php

namespace App\Actions\Pos;

use App\Services\Pos\CustomerDisplay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Give a register's customer-facing display a new version, so it reloads on the next poll. Call it after any change to the
 * order the register shows: a line, a discount, the checkout sheet, or a settlement.
 */
class BumpCfdVersion
{
    public function __invoke(int $registerId): void
    {
        Cache::put(CustomerDisplay::versionKey($registerId), (string) Str::ulid(), now()->addDay());
    }
}
