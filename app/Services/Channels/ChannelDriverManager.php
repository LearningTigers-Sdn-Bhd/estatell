<?php

namespace App\Services\Channels;

use App\Enums\ChannelProvider;
use App\Services\Channels\Channex\ChannexClient;
use App\Services\Channels\Channex\ChannexDriver;
use Illuminate\Support\Manager;

/**
 * Picks the channel manager driver for a provider.
 */
class ChannelDriverManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return ChannelProvider::Channex->value;
    }

    public function forProvider(ChannelProvider $provider): ChannelManagerDriver
    {
        /** @var ChannelManagerDriver */
        return $this->driver($provider->value);
    }

    protected function createChannexDriver(): ChannexDriver
    {
        return new ChannexDriver($this->container->make(ChannexClient::class));
    }
}
