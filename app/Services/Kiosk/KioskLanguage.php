<?php

namespace App\Services\Kiosk;

use App\Enums\Locale;
use Illuminate\Http\Request;

/**
 * The language a kiosk guest asked for in `Accept-Language` (`en`, `ms` or `zh`). Estatell has no Chinese terms yet, so
 * `zh` and any other value give null and the terms fall back to the guest's or the property's language.
 */
class KioskLanguage
{
    public function from(Request $request): ?Locale
    {
        return Locale::tryFrom((string) $request->getPreferredLanguage(['en', 'ms', 'zh']));
    }
}
