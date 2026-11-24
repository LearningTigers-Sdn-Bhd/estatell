<?php

namespace App\Services\Pos;

use App\Models\PosTerminalDevice;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Reads whether a browser is a registered POS terminal and whether it is locked to the POS.
 *
 * A browser holding a registered, unrevoked device cookie is locked until a password-confirmed exit is recorded in its
 * session (`pos_terminal_unlocked`), so an expired session or a remember-me login on a shared till never lands in the back
 * office. Any other browser is locked only after it started Terminal Mode (`pos_terminal_locked`).
 */
class PosTerminal
{
    public function device(Request $request, ?Property $property = null): ?PosTerminalDevice
    {
        $token = $request->cookie(PosTerminalDevice::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return PosTerminalDevice::query()
            ->when($property !== null, fn ($query) => $query->where('property_id', $property->id))
            ->where('device_token_hash', hash('sha256', $token))
            ->active()
            ->first();
    }

    public function isLocked(Request $request, ?Property $property = null): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        if ($request->session()->get('pos_terminal_locked') === true) {
            return true;
        }

        return $request->session()->get('pos_terminal_unlocked') !== true && $this->device($request, $property) !== null;
    }
}
