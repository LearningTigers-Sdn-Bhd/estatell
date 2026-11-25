<?php

namespace App\Http\Controllers\Pos\Concerns;

use App\Models\User;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Shared by the thin POS controllers: the PIN-selected actor, and the toast of a refused or finished action.
 */
trait RespondsToPos
{
    protected function actor(Request $request): User
    {
        $actor = $request->attributes->get('pos_actor');

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * Flash the outcome of an Action: the refusal sentence as an error, else the success sentence. Returns whether it worked.
     */
    protected function toast(?string $refusal, string $success): bool
    {
        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => $success]
            : ['type' => 'error', 'message' => PosRefusalMessages::message($refusal)]);

        return $refusal === null;
    }

    protected function error(string $message): void
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
    }
}
