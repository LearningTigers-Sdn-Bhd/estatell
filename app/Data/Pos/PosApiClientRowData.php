<?php

namespace App\Data\Pos;

use App\Models\PosApiClient;
use Spatie\LaravelData\Data;

/**
 * A POS API client as staff see it. Never carries a token: it is shown once, when the client is added.
 */
class PosApiClientRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
        public bool $canRevoke,
    ) {}

    public static function fromModel(PosApiClient $client): self
    {
        return new self($client->id, $client->name, $client->last_used_at?->toIso8601String(), $client->revoked_at?->toIso8601String(), $client->revoked_at === null);
    }
}
