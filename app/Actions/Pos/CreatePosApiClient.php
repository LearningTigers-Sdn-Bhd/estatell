<?php

namespace App\Actions\Pos;

use App\Models\PosApiClient;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add an external POS client to a property and give it a `pos:*` token. The plain token is returned once and never
 * stored or shown again.
 */
class CreatePosApiClient
{
    /**
     * @return array{client: PosApiClient, token: string}
     */
    public function __invoke(User $actor, Property $property, string $name): array
    {
        return DB::transaction(function () use ($actor, $property, $name): array {
            $client = new PosApiClient(['name' => trim($name)]);
            $client->property_id = $property->id;
            $client->save();

            $token = $client->createToken($client->name, [PosApiClient::TOKEN_ABILITY])->plainTextToken;

            activity()
                ->performedOn($client)
                ->causedBy($actor)
                ->event('api-access.token-created')
                ->withProperties(['property_id' => $property->id, 'name' => $client->name])
                ->log('POS API client created');

            return ['client' => $client, 'token' => $token];
        });
    }
}
