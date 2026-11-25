<?php

namespace App\Actions\AiAssistant;

use App\Models\AiConnection;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add a chat API connection to a property and give it an `ai:chat` token. The plain token is returned once and never
 * stored or shown again. See docs/35-ai-assistant/03-places-and-routes.md#admin.
 */
class CreateAiConnection
{
    /**
     * @return array{connection: AiConnection, token: string}
     */
    public function __invoke(User $actor, Property $property, string $name): array
    {
        return DB::transaction(function () use ($actor, $property, $name): array {
            $connection = new AiConnection(['name' => trim($name)]);
            $connection->property_id = $property->id;
            $connection->save();

            $token = $connection->createToken($connection->name, [AiConnection::TOKEN_ABILITY])->plainTextToken;

            activity()
                ->performedOn($connection)
                ->causedBy($actor)
                ->event('api-access.token-created')
                ->withProperties(['property_id' => $property->id, 'name' => $connection->name])
                ->log('AI connection created');

            return ['connection' => $connection, 'token' => $token];
        });
    }
}
