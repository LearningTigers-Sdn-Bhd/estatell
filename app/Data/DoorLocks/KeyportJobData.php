<?php

namespace App\Data\DoorLocks;

use App\Models\KeyportJob;
use Spatie\LaravelData\Data;

/**
 * A job as the Keyport agent reads it. Field names follow the wire contract in `../estatell-keyport/docs/ESTATELL_CONTRACT.md`.
 * Times are property-local, `Y-m-d H:i:s`, no zone.
 */
class KeyportJobData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $created_at,
        public string $expires_at,
        public array $payload,
    ) {}

    public static function fromModel(KeyportJob $job, string $timeZone): self
    {
        return new self(
            id: $job->public_id,
            type: $job->type->value,
            created_at: $job->created_at?->setTimezone($timeZone)->format('Y-m-d H:i:s') ?? '',
            expires_at: $job->expires_at->setTimezone($timeZone)->format('Y-m-d H:i:s'),
            payload: $job->payload,
        );
    }
}
