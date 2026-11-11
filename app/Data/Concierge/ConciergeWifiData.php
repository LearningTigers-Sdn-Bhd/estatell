<?php

namespace App\Data\Concierge;

use App\Models\PropertyWifiNetwork;
use Spatie\LaravelData\Data;

class ConciergeWifiData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $password,
        public ?string $note,
    ) {}

    public static function fromModel(PropertyWifiNetwork $network): self
    {
        return new self($network->id, $network->name, $network->password, $network->note);
    }
}
