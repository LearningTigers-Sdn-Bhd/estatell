<?php

namespace App\Services\Channels;

use App\Data\Channels\ChannelMapRowData;
use App\Models\ChannelConnection;
use App\Models\ChannelRatePlanMapping;

/**
 * The lines of the map page: one per occupancy of each rate plan on Channex, with the saved mapping or, where there is
 * none, an OTA room and rate whose titles match ours. The admin confirms every line. It reads only.
 */
class ChannelMappingRows
{
    /**
     * @return list<ChannelMapRowData>
     */
    public function forConnection(ChannelConnection $connection, ChannelOtaCatalogue $catalogue): array
    {
        $saved = $connection->otaMappings()->get()->keyBy(fn ($mapping): string => $mapping->channel_rate_plan_mapping_id.'-'.$mapping->occupancy);
        $mappings = $connection->channelProperty->ratePlanMappings()->with(['ratePlan', 'roomType'])->get()
            ->sortBy(fn (ChannelRatePlanMapping $mapping): string => sprintf('%06d-%s', $mapping->roomType->position, $mapping->ratePlan->name));
        $hasSaved = $saved->isNotEmpty();
        $rows = [];

        foreach ($mappings as $mapping) {
            foreach (array_reverse($mapping->sell_mode->occupancies($mapping->roomType)) as $position => $occupancy) {
                $key = "{$mapping->id}-{$occupancy}";
                $existing = $saved->get($key);

                $rows[] = new ChannelMapRowData(
                    key: $key,
                    ratePlanMappingId: $mapping->id,
                    roomType: $mapping->roomType->name,
                    ratePlan: $mapping->ratePlan->name,
                    occupancy: $occupancy,
                    ota: $existing !== null
                        ? "{$existing->ota_room_code}|{$existing->ota_rate_code}"
                        : ($hasSaved ? null : $this->suggest($catalogue, $mapping, $occupancy, $position === 0)),
                    isPrimary: $existing?->is_primary ?? false,
                    rateType: $existing?->rate_type,
                );
            }
        }

        return $this->withPrimaries($rows);
    }

    /**
     * A line with a mapped OTA rate and no primary among its siblings makes its highest occupancy primary, because
     * Channex needs exactly one primary mapping for each OTA room and rate.
     *
     * @param  list<ChannelMapRowData>  $rows
     * @return list<ChannelMapRowData>
     */
    private function withPrimaries(array $rows): array
    {
        $primaryOf = [];

        foreach ($rows as $row) {
            if ($row->ota !== null && $row->isPrimary) {
                $primaryOf[$row->ota] = true;
            }
        }

        foreach ($rows as $index => $row) {
            if ($row->ota !== null && ! isset($primaryOf[$row->ota])) {
                $primaryOf[$row->ota] = true;
                $rows[$index] = new ChannelMapRowData($row->key, $row->ratePlanMappingId, $row->roomType, $row->ratePlan, $row->occupancy, $row->ota, true, $row->rateType);
            }
        }

        return $rows;
    }

    /**
     * A hotel with per-room pricing takes one mapping for each rate, so only the top occupancy is suggested. An OBP rate
     * takes the occupancies it lists.
     */
    private function suggest(ChannelOtaCatalogue $catalogue, ChannelRatePlanMapping $mapping, int $occupancy, bool $isTop): ?string
    {
        if ($catalogue->pricingType !== 'OBP' && ! $isTop) {
            return null;
        }

        foreach ($catalogue->rooms as $room) {
            if ($this->normalize($room['title'] ?? '') !== $this->normalize($mapping->roomType->name)) {
                continue;
            }

            foreach ($room['rates'] ?? [] as $rate) {
                $occupancies = $rate['occupancies'] ?? [];

                if ($this->normalize($rate['title'] ?? '') === $this->normalize($mapping->ratePlan->name) && ($occupancies === [] || in_array($occupancy, $occupancies, true))) {
                    return ($room['id'] ?? '').'|'.($rate['id'] ?? '');
                }
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $text));
    }
}
