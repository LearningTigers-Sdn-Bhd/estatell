<?php

namespace App\Services\Channels;

/**
 * The rooms and rates an OTA hotel exposes, as the channel manager read them. `rooms` keep the OTA's own arrays so the
 * codes keep their type when they go back in a mapping.
 */
final readonly class ChannelOtaCatalogue
{
    /**
     * @param  list<array<string, mixed>>  $rooms
     */
    public function __construct(public ChannelActionResult $result, public string $pricingType = '', public array $rooms = []) {}

    /**
     * One OTA room and rate, found by codes. Codes compare as text.
     *
     * @return array{room: array<string, mixed>, rate: array<string, mixed>}|null
     */
    public function find(string $roomCode, string $rateCode): ?array
    {
        foreach ($this->rooms as $room) {
            if ((string) ($room['id'] ?? '') !== $roomCode) {
                continue;
            }

            foreach ($room['rates'] ?? [] as $rate) {
                if ((string) ($rate['id'] ?? '') === $rateCode) {
                    return ['room' => $room, 'rate' => $rate];
                }
            }
        }

        return null;
    }
}
