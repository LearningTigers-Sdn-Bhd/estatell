<?php

namespace App\Services\Channels\Channex;

use App\Services\Channels\ChannelManagerDriver;
use LogicException;

/**
 * The Channex implementation. Only `adapter` is built here. The other calls arrive with the commit that needs them
 * and throw until then, so the interface stays stable.
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
        $this->notBuilt('sync property structure to Channex');
    }

    public function pushAri(string $externalPropertyId, array $restrictions, array $availability): void
    {
        $this->notBuilt('sync availability, rates and restrictions');
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

    private function notBuilt(string $commit): never
    {
        throw new LogicException("Not built yet: {$commit}.");
    }
}
