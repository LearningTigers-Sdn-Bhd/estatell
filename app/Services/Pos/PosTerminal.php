<?php

namespace App\Services\Pos;

use App\Features\Pos;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * Reads whether a browser is a registered POS terminal and whether it is locked to the POS.
 *
 * A browser holding a registered, unrevoked device cookie is locked until a password-confirmed exit is recorded in its
 * session (`pos_terminal_unlocked`), so an expired session or a remember-me login on a shared till never lands in the back
 * office. Any other browser is locked only after it started Terminal Mode (`pos_terminal_locked`).
 *
 * Neither lock holds while the POS feature of its property is off: every `/pos` route, the way out included, answers 404
 * then. The lock returns when the feature is back on.
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

        if ($request->session()->get('pos_terminal_locked') === true && $this->sessionLockHolds($request, $property)) {
            return true;
        }

        if ($request->session()->get('pos_terminal_unlocked') === true) {
            return false;
        }

        $device = $this->device($request, $property);

        return $device !== null && Feature::for($device->property)->active(Pos::class);
    }

    /**
     * The session lock belongs to the property where Terminal Mode started. A session without that record falls back to
     * the property in question, and stays locked when there is none.
     */
    private function sessionLockHolds(Request $request, ?Property $property): bool
    {
        $startedAt = $request->session()->get('pos_terminal_property_id');
        $owner = $startedAt !== null ? Property::query()->find($startedAt) : ($property ?? $request->route('property') ?? $request->user()?->property);

        return ! $owner instanceof Property || Feature::for($owner)->active(Pos::class);
    }
}
