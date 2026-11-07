<?php

namespace App\Actions\DoorLocks;

use App\Models\KeyportDevice;

/**
 * Save the health a Keyport PC sends with each claim, so staff can see it is online and what its readers report.
 */
class RecordKeyportHeartbeat
{
    /**
     * @param  array{agent_version?: string|null, simulator?: bool, readers?: array<int, array{kind: string, state: string}>, client_code_present?: bool}  $health
     */
    public function __invoke(KeyportDevice $device, array $health): void
    {
        $device->forceFill([
            'last_seen_at' => now(),
            'agent_version' => $health['agent_version'] ?? $device->agent_version,
            'simulator' => $health['simulator'] ?? false,
            'client_code_present' => $health['client_code_present'] ?? false,
            'reader_state' => $health['readers'] ?? [],
        ])->save();
    }
}
