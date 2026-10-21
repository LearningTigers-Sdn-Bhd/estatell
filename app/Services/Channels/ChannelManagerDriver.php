<?php

namespace App\Services\Channels;

use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Closure;

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
     *                                                                                                                                                                                                                                                                                      `$onProgress` is called with the steps done and the steps in all, after the property, each room type and each rate plan.
     * @param  (Closure(int, int): void)|null  $onProgress
     * @return array{property_id: string, room_types: array<int, array{id: string, rate_plans: array<int, string>}>}
     *
     * @throws ChannelStructureSyncFailed
     */
    public function syncStructure(array $structure, ?Closure $onProgress = null): array;

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
     * Check the OTA settings without saving a connection. The provider asks the OTA, so this needs no property.
     *
     * @param  array<string, mixed>  $settings
     * @return array{success: bool, errors: mixed}
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function testConnection(string $adapterCode, array $settings): array;

    /**
     * The OTA rooms and rates a connection can map to, read from the OTA with the connection settings. It needs no
     * channel at the provider. The shape follows Channex: `pricing_type` and `rooms`, each with its `rates`.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function mappingDetails(string $adapterCode, array $settings): array;

    /**
     * Create the OTA connection at the provider. It starts disabled. Returns the provider's channel with its `id`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function createChannel(array $payload): array;

    /**
     * Change the OTA connection at the provider. A `rate_plans` list replaces the whole mapping set there.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function updateChannel(string $externalChannelId, array $payload): array;

    /**
     * Start the connection: the provider sends the OTA its current availability, rates and restrictions and starts
     * collecting bookings. It refuses a connection with no rate mapping.
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function activate(string $externalChannelId): void;

    /**
     * Stop the connection. The provider keeps it and its mapping.
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function deactivate(string $externalChannelId): void;

    /**
     * Remove the connection and its mapping at the provider. It must be deactivated first. A connection the provider no
     * longer has is gone already. Bookings the connection brought are kept.
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function deleteChannel(string $externalChannelId): void;

    /**
     * Ask the OTA for its upcoming bookings. They arrive as booking revisions.
     *
     * @throws ChannexRetryLater when the provider cannot take it now
     * @throws ChannexRequestFailed when the provider refuses it
     */
    public function loadFutureReservations(string $externalChannelId): void;
}
