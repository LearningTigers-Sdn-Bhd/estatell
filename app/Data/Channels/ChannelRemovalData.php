<?php

namespace App\Data\Channels;

use App\Enums\ChannelRemovalKind;
use App\Enums\ChannelRemovalState;
use Spatie\LaravelData\Data;

/**
 * One archive or unlink that waited for the channel manager, as the staff member who asked sees it: the toast and the
 * list refresh follow the state.
 */
class ChannelRemovalData extends Data
{
    public function __construct(
        public string $key,
        public ChannelRemovalKind $kind,
        public string $name,
        public ChannelRemovalState $state,
        public ?string $message,
        public string $updatedAt,
    ) {}
}
