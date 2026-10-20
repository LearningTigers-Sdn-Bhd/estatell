<?php

namespace App\Services\Channels;

/**
 * What every channel manager provider must do. The signatures are a first cut: the commits that build each call
 * (structure sync, ARI, bookings, the Channel API) may tighten the array shapes but keep the names.
 */
interface ChannelManagerDriver
{
    /**
     * Create or update the property, room types and rate plans at the provider.
     *
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    public function syncStructure(array $structure): array;

    /**
     * Send availability and restriction/price changes for one provider property.
     *
     * @param  list<array<string, mixed>>  $restrictions
     * @param  list<array<string, mixed>>  $availability
     */
    public function pushAri(string $externalPropertyId, array $restrictions, array $availability): void;

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
