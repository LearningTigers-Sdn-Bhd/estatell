<?php

namespace App\Services\Channels;

use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

/**
 * What every channel manager provider must do. The signatures are a first cut: the commits that build each call
 * (structure sync, ARI, bookings, the Channel API) may tighten the array shapes but keep the names.
 */
interface ChannelManagerDriver
{
    /**
     * Create or update the property, its room types and its rate plans at the provider. A missing `external_id`
     * creates, a present one updates. It never removes anything. If it stops part-way it throws
     * `ChannelStructureSyncFailed` with the ids saved so far.
     *
     * @param  array{property: array{external_id: string|null, payload: array<string, mixed>}, room_types: array<int, array{external_id: string|null, payload: array<string, mixed>, rate_plans: array<int, array{external_id: string|null, payload: array<string, mixed>}>}>}  $structure
     * @return array{property_id: string, room_types: array<int, array{id: string, rate_plans: array<int, string>}>}
     *
     * @throws ChannelStructureSyncFailed
     */
    public function syncStructure(array $structure): array;

    /**
     * Remove a rate plan at the provider. The provider refuses while an OTA still uses it.
     */
    public function removeRatePlan(string $externalRatePlanId): void;

    /**
     * Remove a room type at the provider. The provider refuses while an OTA still uses it.
     */
    public function removeRoomType(string $externalRoomTypeId): void;

    /**
     * Send rates and restrictions in one call: the `values` of the provider's `/restrictions` message. Returns the
     * provider's task id, if any.
     *
     * @param  list<array<string, mixed>>  $values
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function pushRestrictions(array $values): ?string;

    /**
     * Send free units in one call: the `values` of the provider's `/availability` message. Returns the task id.
     *
     * @param  list<array<string, mixed>>  $values
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function pushAvailability(array $values): ?string;

    /**
     * Get the booking revisions that are not acknowledged yet.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRevisions(string $externalPropertyId): array;

    /**
     * Tell the provider a revision is saved. Call it only after the booking is stored.
     */
    public function acknowledge(string $externalRevisionId): void;

    /**
     * The connection and mapping form fields of one OTA adapter.
     *
     * @return array<string, mixed>
     */
    public function adapter(string $adapterCode): array;

    /**
     * Check the OTA settings without saving a connection.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function testConnection(string $externalPropertyId, string $adapterCode, array $settings): array;

    /**
     * The OTA rooms and rates a connection can map to.
     *
     * @return array<string, mixed>
     */
    public function mappingDetails(string $externalChannelId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createChannel(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateChannel(string $externalChannelId, array $payload): array;

    public function activate(string $externalChannelId): void;

    public function deactivate(string $externalChannelId): void;

    public function loadFutureReservations(string $externalChannelId): void;
}
