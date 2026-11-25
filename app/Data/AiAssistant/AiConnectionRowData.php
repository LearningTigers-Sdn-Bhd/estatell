<?php

namespace App\Data\AiAssistant;

use App\Models\AiConnection;
use Spatie\LaravelData\Data;

/**
 * A chat API connection as the platform admin sees it. Never carries a token: it is shown once, when the connection is
 * added.
 */
class AiConnectionRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
        public bool $canRevoke,
    ) {}

    public static function fromModel(AiConnection $connection): self
    {
        return new self($connection->id, $connection->name, $connection->last_used_at?->toIso8601String(), $connection->revoked_at?->toIso8601String(), $connection->revoked_at === null);
    }
}
