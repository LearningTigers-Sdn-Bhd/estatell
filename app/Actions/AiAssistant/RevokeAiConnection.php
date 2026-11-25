<?php

namespace App\Actions\AiAssistant;

use App\Models\AiConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a chat API connection. Its token is deleted and stops working at once; the row stays so the admin still sees
 * it, and its chats stay in the AI chats inbox. Returns false, writing nothing, when it is already revoked.
 */
class RevokeAiConnection
{
    public function __invoke(User $actor, AiConnection $connection): bool
    {
        if ($connection->revoked_at !== null) {
            return false;
        }

        DB::transaction(function () use ($actor, $connection): void {
            $connection->forceFill(['revoked_at' => now()])->save();
            $connection->tokens()->delete();

            activity()
                ->performedOn($connection)
                ->causedBy($actor)
                ->event('api-access.token-revoked')
                ->withProperties(['property_id' => $connection->property_id, 'name' => $connection->name])
                ->log('AI connection revoked');
        });

        return true;
    }
}
