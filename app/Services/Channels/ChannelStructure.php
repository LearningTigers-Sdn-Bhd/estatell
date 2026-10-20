<?php

namespace App\Services\Channels;

use Illuminate\Support\Collection;

/**
 * What a sync would send: every active pair with its answer, and a reason when the whole sync is blocked.
 */
final readonly class ChannelStructure
{
    /**
     * @param  list<ChannelStructurePair>  $pairs
     */
    public function __construct(public array $pairs, public ?string $blockedReason = null) {}

    /**
     * @return Collection<int, ChannelStructurePair>
     */
    public function sent(): Collection
    {
        return collect($this->pairs)->filter(fn (ChannelStructurePair $pair): bool => $pair->result->support->isSent())->values();
    }

    public function skippedCount(): int
    {
        return count($this->pairs) - $this->sent()->count();
    }

    public function roomTypeCount(): int
    {
        return $this->sent()->map(fn (ChannelStructurePair $pair): int => $pair->roomType->id)->unique()->count();
    }

    public function ratePlanCount(): int
    {
        return $this->sent()->count();
    }
}
