<?php

namespace App\Data\Channels;

use App\Models\OtaChannel;
use Spatie\LaravelData\Data;

class OtaChannelData extends Data
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public int $sortOrder,
        public bool $isActive,
        public bool $isOther,
    ) {}

    public static function fromModel(OtaChannel $channel): self
    {
        return new self(
            id: $channel->id,
            code: $channel->code,
            name: $channel->name,
            sortOrder: $channel->sort_order,
            isActive: $channel->is_active,
            isOther: $channel->is_other,
        );
    }
}
