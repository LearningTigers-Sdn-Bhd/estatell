<?php

namespace App\Actions\Pos;

use App\Enums\PosPinOutcome;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Pick a name and enter a PIN to make that staff member the terminal's active actor. Only works on a registered device that is
 * locked to the POS (Terminal Mode, or not unlocked with a password since the session began). Every `/pos` route then authorizes against that person, never against the browser user.
 */
class SwitchPosActor
{
    public function __construct(private VerifyPosPin $verifyPosPin) {}

    public function __invoke(Session $session, Property $property, PosTerminalDevice $device, User $target, string $pin): PosPinResult
    {
        if ($session->get('pos_terminal_unlocked') === true) {
            return PosPinResult::refused(PosPinOutcome::TerminalModeRequired);
        }

        $result = ($this->verifyPosPin)($property, $device, $target, $pin);

        if ($result->isVerified()) {
            $session->put('pos_active_actor_id', $target->id);
        }

        return $result;
    }
}
