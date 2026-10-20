<?php

namespace App\Services\Channels\Channex;

use App\Services\Channels\ChannelManagerDriver;
use App\Services\Channels\ChannelStructureSyncFailed;
use LogicException;

/**
 * The Channex implementation. `adapter`, the structure calls and the ARI pushes are built. The other calls arrive with the commit that
 * needs them and throw until then, so the interface stays stable.
 */
class ChannexDriver implements ChannelManagerDriver
{
    public function __construct(private readonly ChannexClient $client) {}

    public function adapter(string $adapterCode): array
    {
        return $this->client->get('/channels/adapter', ['code' => $adapterCode])['data'] ?? [];
    }

    public function syncStructure(array $structure): array
    {
        $partial = ['property_id' => null, 'room_types' => []];

        try {
            ['id' => $propertyId, 'max_room_types' => $maxRoomTypes, 'max_rate_plans' => $maxRatePlans] = $this->upsertProperty($structure['property']);
            $partial['property_id'] = $propertyId;
            $this->assertWithinLimits($structure, $maxRoomTypes, $maxRatePlans, $partial);

            foreach ($structure['room_types'] as $roomTypeKey => $roomType) {
                $roomTypeId = $this->upsert('/room_types', 'room_type', $roomType['external_id'], [...$roomType['payload'], 'property_id' => $propertyId]);
                $partial['room_types'][$roomTypeKey] = ['id' => $roomTypeId, 'rate_plans' => []];

                foreach ($roomType['rate_plans'] as $ratePlanKey => $ratePlan) {
                    $partial['room_types'][$roomTypeKey]['rate_plans'][$ratePlanKey] = $this->upsert('/rate_plans', 'rate_plan', $ratePlan['external_id'], [
                        ...$ratePlan['payload'],
                        'property_id' => $propertyId,
                        'room_type_id' => $roomTypeId,
                    ]);
                }
            }
        } catch (ChannexRetryLater $exception) {
            throw new ChannelStructureSyncFailed(__('Channex is busy or not reachable. Try again in a minute.'), $partial, retryLater: true, previous: $exception);
        } catch (ChannexRequestFailed $exception) {
            throw new ChannelStructureSyncFailed($this->failureMessage($exception), $partial, previous: $exception);
        }

        return ['property_id' => $propertyId, 'room_types' => $partial['room_types']];
    }

    public function removeRatePlan(string $externalRatePlanId): void
    {
        $this->remove("/rate_plans/{$externalRatePlanId}");
    }

    public function removeRoomType(string $externalRoomTypeId): void
    {
        $this->remove("/room_types/{$externalRoomTypeId}");
    }

    public function pushRestrictions(array $values): ?string
    {
        return $this->push('/restrictions', $values);
    }

    public function pushAvailability(array $values): ?string
    {
        return $this->push('/availability', $values);
    }

    public function fetchRevisions(string $externalPropertyId): array
    {
        $this->notBuilt('ingest channel bookings');
    }

    public function acknowledge(string $externalRevisionId): void
    {
        $this->notBuilt('ingest channel bookings');
    }

    public function testConnection(string $externalPropertyId, string $adapterCode, array $settings): array
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function mappingDetails(string $externalChannelId): array
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function createChannel(array $payload): array
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function updateChannel(string $externalChannelId, array $payload): array
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function activate(string $externalChannelId): void
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function deactivate(string $externalChannelId): void
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    public function loadFutureReservations(string $externalChannelId): void
    {
        $this->notBuilt('connect and map OTAs through the Channel API');
    }

    /**
     * Channex answers 200 even when it rejects a value, and lists the rejects in `meta.warnings`. A warning is a
     * refusal here, so the change is not marked sent.
     *
     * @param  list<array<string, mixed>>  $values
     */
    private function push(string $path, array $values): ?string
    {
        $response = $this->client->post($path, ['values' => $values]);
        $warnings = $response['meta']['warnings'] ?? [];

        if ($warnings !== []) {
            throw new ChannexRequestFailed('Channex rejected some values.', 422, ['details' => $warnings]);
        }

        $taskId = $response['data'][0]['id'] ?? null;

        return is_string($taskId) ? $taskId : null;
    }

    private function notBuilt(string $commit): never
    {
        throw new LogicException("Not built yet: {$commit}.");
    }

    /**
     * Create the property, or update it. The group is set on create only. Checks the limits Channex reports.
     *
     * @param  array{external_id: string|null, payload: array<string, mixed>}  $property
     * @return array{id: string, max_room_types: int|null, max_rate_plans: int|null}
     */
    private function upsertProperty(array $property): array
    {
        $payload = $property['payload'];

        if ($property['external_id'] !== null) {
            unset($payload['group_id']);
        }

        $response = $property['external_id'] === null
            ? $this->client->post('/properties', ['property' => $payload])
            : $this->client->put("/properties/{$property['external_id']}", ['property' => $payload]);

        $attributes = $response['data']['attributes'] ?? [];

        return [
            'id' => $this->idOf($response),
            'max_room_types' => isset($attributes['max_count_of_room_types']) ? (int) $attributes['max_count_of_room_types'] : null,
            'max_rate_plans' => isset($attributes['max_count_of_rate_plans']) ? (int) $attributes['max_count_of_rate_plans'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @param  array{property_id: string|null, room_types: array<int, array{id: string, rate_plans: array<int, string>}>}  $partial
     *
     * @throws ChannelStructureSyncFailed
     */
    private function assertWithinLimits(array $structure, ?int $maxRoomTypes, ?int $maxRatePlans, array $partial): void
    {
        $ratePlans = array_sum(array_map(fn (array $roomType): int => count($roomType['rate_plans']), $structure['room_types']));

        if ($maxRoomTypes !== null && count($structure['room_types']) > $maxRoomTypes) {
            throw new ChannelStructureSyncFailed(__('Channex allows :max room types for this property, and :count were to be sent.', ['max' => $maxRoomTypes, 'count' => count($structure['room_types'])]), $partial);
        }

        if ($maxRatePlans !== null && $ratePlans > $maxRatePlans) {
            throw new ChannelStructureSyncFailed(__('Channex allows :max rate plans for this property, and :count were to be sent.', ['max' => $maxRatePlans, 'count' => $ratePlans]), $partial);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsert(string $path, string $key, ?string $externalId, array $payload): string
    {
        $response = $externalId === null
            ? $this->client->post($path, [$key => $payload])
            : $this->client->put("{$path}/{$externalId}", [$key => $payload]);

        return $this->idOf($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function idOf(array $response): string
    {
        return (string) ($response['data']['id'] ?? throw new ChannexRequestFailed('Channex answered without an id.', 502));
    }

    /**
     * A 404 means it is already gone, which is what the caller wants.
     */
    private function remove(string $path): void
    {
        try {
            $this->client->delete($path);
        } catch (ChannexRequestFailed $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }

    private function failureMessage(ChannexRequestFailed $exception): string
    {
        $details = $exception->errors['details'] ?? null;

        return $details === null
            ? __('Channex refused the request (:status).', ['status' => $exception->status])
            : __('Channex refused the request (:status): :details', ['status' => $exception->status, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
