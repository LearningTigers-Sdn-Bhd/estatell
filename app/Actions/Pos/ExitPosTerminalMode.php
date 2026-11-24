<?php

namespace App\Actions\Pos;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;

/**
 * Leave Terminal Mode. Needs the browser user's own password, so a cashier on a shared tablet cannot walk into the back
 * office. Returns false on a wrong password and changes nothing.
 */
class ExitPosTerminalMode
{
    public function __invoke(Session $session, User $user, string $password): bool
    {
        if (! Hash::check($password, $user->password)) {
            return false;
        }

        $session->forget(['pos_terminal_locked', 'pos_active_actor_id']);

        return true;
    }
}
