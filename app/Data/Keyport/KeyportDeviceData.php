<?php

namespace App\Data\Keyport;

use App\Data\Shared\BadgeData;
use App\Enums\KeyportDeviceStatus;
use App\Enums\Tone;
use App\Models\KeyportDevice;
use Spatie\LaravelData\Data;

/**
 * A front-desk PC as staff see it. Never carries a token or a pairing code.
 */
class KeyportDeviceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $platform,
        public BadgeData $status,
        public ?string $lastSeenAt,
        public ?string $readerNote,
        public bool $canRevoke,
    ) {}

    public static function fromModel(KeyportDevice $device): self
    {
        $status = self::status($device);

        return new self(
            id: $device->id,
            name: $device->name,
            platform: $device->platform,
            status: new BadgeData($status->value, $status->label(), Tone::from($status->tone())),
            lastSeenAt: $device->last_seen_at?->toIso8601String(),
            readerNote: $status === KeyportDeviceStatus::Online ? self::readerNote($device) : null,
            canRevoke: $status !== KeyportDeviceStatus::Revoked,
        );
    }

    /**
     * Online means a claim in the last minute: the claim is the heartbeat.
     */
    public static function status(KeyportDevice $device): KeyportDeviceStatus
    {
        return match (true) {
            $device->revoked_at !== null => KeyportDeviceStatus::Revoked,
            $device->last_seen_at?->gt(now()->subSeconds(config()->integer('keyport.online_seconds'))) === true => KeyportDeviceStatus::Online,
            default => KeyportDeviceStatus::Offline,
        };
    }

    /**
     * What staff need to know before they put a card down: a reader problem, a test-mode PC, or a missing site code.
     */
    private static function readerNote(KeyportDevice $device): string
    {
        $connected = collect($device->reader_state ?? [])->contains(fn (array $reader): bool => $reader['state'] === 'connected');

        return match (true) {
            $device->simulator => __('Test mode: no real cards are written.'),
            ! $connected => __('No card reader connected.'),
            ! $device->client_code_present => __('Site code not set on this computer.'),
            default => __('Card reader ready.'),
        };
    }
}
