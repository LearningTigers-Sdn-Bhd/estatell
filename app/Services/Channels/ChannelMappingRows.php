<?php

namespace App\Services\Channels;

use App\Data\Channels\ChannelEstatellOptionData;
use App\Data\Channels\ChannelMapRowData;
use App\Enums\ChannelSellMode;
use App\Models\ChannelConnection;
use App\Models\ChannelRatePlanMapping;

/**
 * The lines of the map page: one per room, rate and guest count the OTA lists, with the saved estatell plan or, where
 * there is none, a plan whose room and rate titles match. The admin confirms every line. It reads only.
 */
class ChannelMappingRows
{
    /**
     * @return list<ChannelMapRowData>
     */
    public function forConnection(ChannelConnection $connection, ChannelOtaCatalogue $catalogue): array
    {
        $saved = $connection->otaMappings()->get();
        $hasSaved = $saved->isNotEmpty();
        $choices = $this->choices($connection);
        $taken = [];
        $rows = [];

        foreach ($catalogue->rooms as $room) {
            foreach ($room['rates'] ?? [] as $rate) {
                $roomCode = (string) ($room['id'] ?? '');
                $rateCode = (string) ($rate['id'] ?? '');
                $occupancies = collect($rate['occupancies'] ?? [])->map(fn ($occupancy): int => (int) $occupancy)->unique()->sortDesc()->values()->all();

                foreach ($occupancies === [] ? [null] : $occupancies as $occupancy) {
                    $existing = $saved->first(fn ($mapping): bool => (string) $mapping->ota_room_code === $roomCode
                        && (string) $mapping->ota_rate_code === $rateCode
                        && ($occupancy === null || $mapping->occupancy === $occupancy)
                        && ! isset($taken[$mapping->id]));

                    if ($existing !== null) {
                        $taken[$existing->id] = true;
                    }

                    $value = $existing !== null
                        ? (string) $existing->channel_rate_plan_mapping_id
                        : ($hasSaved ? null : $this->suggest($choices, $room['title'] ?? '', $rate['title'] ?? '', $occupancy));

                    $rows[] = [
                        'key' => "{$roomCode}|{$rateCode}|".($occupancy ?? 'any'),
                        'roomCode' => $roomCode,
                        'roomTitle' => (string) ($room['title'] ?? __('Room')),
                        'rateCode' => $rateCode,
                        'rateTitle' => (string) ($rate['title'] ?? __('Rate')),
                        'occupancy' => $occupancy,
                        'estatell' => $value,
                        'isPrimary' => $existing?->is_primary ?? false,
                        'rateType' => $existing?->rate_type,
                    ];
                }
            }
        }

        return $this->withPrimaries($rows);
    }

    /**
     * Every estatell plan occupancy an OTA line can take.
     *
     * @return list<ChannelEstatellOptionData>
     */
    public function options(ChannelConnection $connection): array
    {
        return array_map(
            fn (array $choice): ChannelEstatellOptionData => new ChannelEstatellOptionData($choice['value'], $choice['roomTypeId'], $choice['roomType'], $choice['ratePlan'], $choice['perRoom'], $choice['occupancies']),
            $this->choices($connection),
        );
    }

    /**
     * @return list<array{value: string, roomTypeId: int, perRoom: bool, occupancies: list<int>, roomType: string, ratePlan: string}>
     */
    private function choices(ChannelConnection $connection): array
    {
        $choices = [];
        $mappings = $connection->channelProperty->ratePlanMappings()->with(['ratePlan', 'roomType'])->get()
            ->sortBy(fn (ChannelRatePlanMapping $mapping): string => sprintf('%06d-%s', $mapping->roomType->position, $mapping->ratePlan->name));

        foreach ($mappings as $mapping) {
            $choices[] = [
                'value' => (string) $mapping->id,
                'roomTypeId' => $mapping->room_type_id,
                'perRoom' => $mapping->sell_mode === ChannelSellMode::PerRoom,
                'occupancies' => $mapping->sell_mode->occupancies($mapping->roomType),
                'roomType' => $mapping->roomType->name,
                'ratePlan' => $mapping->ratePlan->name,
            ];
        }

        return $choices;
    }

    /**
     * The plan whose room type and name equal the OTA room and rate titles, if it can take the line's guest count: a plan
     * sold per room takes any, a plan sold per person only a count it has.
     *
     * @param  list<array{value: string, roomTypeId: int, perRoom: bool, occupancies: list<int>, roomType: string, ratePlan: string}>  $choices
     */
    private function suggest(array $choices, string $roomTitle, string $rateTitle, ?int $occupancy): ?string
    {
        foreach ($choices as $choice) {
            $fits = $occupancy === null || $choice['perRoom'] || in_array($occupancy, $choice['occupancies'], true);

            if ($fits && $this->normalize($choice['roomType']) === $this->normalize($roomTitle) && $this->normalize($choice['ratePlan']) === $this->normalize($rateTitle)) {
                return $choice['value'];
            }
        }

        return null;
    }

    /**
     * An OTA room and rate with mapped lines and no primary among them makes its highest occupancy primary, because
     * Channex needs exactly one primary mapping for each. The lines of a room and rate are in highest-first order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<ChannelMapRowData>
     */
    private function withPrimaries(array $rows): array
    {
        $primaryOf = [];

        foreach ($rows as $row) {
            if ($row['estatell'] !== null && $row['isPrimary']) {
                $primaryOf["{$row['roomCode']}|{$row['rateCode']}"] = true;
            }
        }

        foreach ($rows as $index => $row) {
            $group = "{$row['roomCode']}|{$row['rateCode']}";

            if ($row['estatell'] !== null && ! isset($primaryOf[$group])) {
                $primaryOf[$group] = true;
                $rows[$index]['isPrimary'] = true;
            }
        }

        return array_map(fn (array $row): ChannelMapRowData => new ChannelMapRowData(...$row), $rows);
    }

    private function normalize(string $text): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $text));
    }
}
