<?php

namespace App\Services\Channels;

/**
 * The outcome of checking the settings of a new OTA connection. `settings` is what Channex will take.
 */
final readonly class ChannelConnectionCheck
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(public ChannelActionResult $result, public array $settings = []) {}
}
