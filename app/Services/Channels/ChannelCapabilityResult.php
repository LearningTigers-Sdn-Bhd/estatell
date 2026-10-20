<?php

namespace App\Services\Channels;

use App\Enums\ChannelSupport;

/**
 * Whether one room type and rate plan pair can go to the channel manager. `detail` is the reason a pair is not sent, or
 * the note on how a flattened pair is sent.
 */
final readonly class ChannelCapabilityResult
{
    public function __construct(public ChannelSupport $support, public ?string $detail = null) {}
}
