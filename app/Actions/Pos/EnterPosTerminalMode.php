<?php

namespace App\Actions\Pos;

use App\Models\Property;
use Illuminate\Contracts\Session\Session;

/**
 * Lock this browser session to the POS. Until it is left with a fresh password, every route outside `/pos` is refused and
 * the cashier signs in by PIN. The PIN-selected actor starts empty.
 */
class EnterPosTerminalMode
{
    public function __invoke(Session $session, Property $property): void
    {
        $session->put(['pos_terminal_locked' => true, 'pos_terminal_property_id' => $property->id]);
        $session->forget(['pos_active_actor_id', 'pos_terminal_unlocked']);
    }
}
