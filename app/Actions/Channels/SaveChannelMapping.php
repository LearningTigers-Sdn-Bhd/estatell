<?php

namespace App\Actions\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelConnection;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\ChannelOtaCatalogueLoader;
use App\Services\Channels\Channex\ChannexChannelMapper;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Illuminate\Support\Facades\DB;

class SaveChannelMapping
{
    public function __construct(private ChannelDriverManager $drivers, private ChannelOtaCatalogueLoader $catalogueLoader, private ChannexChannelMapper $mapper) {}

    /**
     * Replace the connection's mapping at Channex and here. The OTA's rooms and rates are read again, so a line that names
     * a room or rate the OTA does not have is refused. Where the adapter needs a primary occupancy, each OTA room and rate
     * gets exactly one: the flagged line with the highest occupancy, or the highest occupancy when none is flagged.
     * Nothing is saved here unless Channex accepted the mapping.
     *
     * @param  list<array{rate_plan_mapping_id: int, occupancy: int, ota: string|null, is_primary?: bool, rate_type?: string|null}>  $lines
     */
    public function __invoke(User $admin, ChannelConnection $connection, array $lines): ChannelActionResult
    {
        $connection->loadMissing('otaChannel', 'channelProperty.property');
        $mapped = array_values(array_filter($lines, fn (array $line): bool => filled($line['ota'] ?? null)));
        $ratePlans = $connection->channelProperty->ratePlanMappings()->with('roomType')->get()->keyBy('id');

        foreach ($mapped as $line) {
            $ratePlan = $ratePlans->get($line['rate_plan_mapping_id']);

            if ($ratePlan === null || ! in_array($line['occupancy'], $ratePlan->sell_mode->occupancies($ratePlan->roomType), true)) {
                return ChannelActionResult::failed(__('A line does not match a rate plan of this property. Reload the page and try again.'));
            }
        }

        if (count(array_unique(array_map(fn (array $line): string => $line['rate_plan_mapping_id'].'-'.$line['occupancy'], $mapped))) !== count($mapped)) {
            return ChannelActionResult::failed(__('A rate plan occupancy is mapped twice. Reload the page and try again.'));
        }

        $catalogue = ($this->catalogueLoader)($connection);

        if (! $catalogue->result->ok) {
            return $catalogue->result;
        }

        $driver = $this->drivers->forProvider(ChannelProvider::Channex);

        try {
            $adapter = $driver->adapter((string) $connection->otaChannel->channex_code);
        } catch (ChannexRetryLater) {
            return ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.'));
        } catch (ChannexRequestFailed) {
            return ChannelActionResult::failed(__('Channex did not describe :channel. Try again in a minute.', ['channel' => $connection->otaChannel->name]));
        }

        $resolved = [];

        foreach ($mapped as $line) {
            [$roomCode, $rateCode] = array_pad(explode('|', (string) $line['ota'], 2), 2, '');
            $found = $catalogue->find($roomCode, $rateCode);

            if ($found === null) {
                return ChannelActionResult::failed(__(':channel no longer has a room or rate you chose. Fetch the rooms and rates again.', ['channel' => $connection->otaChannel->name]));
            }

            $resolved[] = [...$line, 'roomCode' => $roomCode, 'rateCode' => $rateCode, 'room' => $found['room'], 'rate' => $found['rate']];
        }

        $usesPrimary = array_key_exists('primary_occ', $adapter['rate_params'] ?? []);
        $primaries = $usesPrimary ? $this->primaries($resolved) : [];

        $entries = array_map(fn (array $line): array => $this->mapper->rateMapping(
            $adapter,
            $ratePlans->get($line['rate_plan_mapping_id'])->external_rate_plan_id,
            $line['room'],
            $line['rate'],
            $line['occupancy'],
            in_array($line['rate_plan_mapping_id'].'-'.$line['occupancy'], $primaries, true),
            $line['rate_type'] ?? null,
            $catalogue->pricingType,
        ), $resolved);

        $payload = [...$this->mapper->createPayload(
            (string) $connection->otaChannel->channex_code,
            "{$connection->otaChannel->name} - {$connection->channelProperty->property->name}",
            (string) config('services.channex.group_id'),
            $connection->channelProperty->external_property_id,
            $connection->settings,
        ), 'rate_plans' => $entries];

        try {
            $driver->updateChannel((string) $connection->external_channel_id, $payload);
        } catch (ChannexRetryLater) {
            return ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.'));
        } catch (ChannexRequestFailed) {
            return ChannelActionResult::failed(__('Channex refused the mapping. Check that each OTA rate takes the occupancy you chose.'));
        }

        DB::transaction(function () use ($connection, $resolved, $primaries, $usesPrimary): void {
            $connection->otaMappings()->delete();

            foreach ($resolved as $line) {
                $connection->otaMappings()->create([
                    'channel_rate_plan_mapping_id' => $line['rate_plan_mapping_id'],
                    'occupancy' => $line['occupancy'],
                    'ota_room_code' => $line['roomCode'],
                    'ota_rate_code' => $line['rateCode'],
                    'is_primary' => $usesPrimary ? in_array($line['rate_plan_mapping_id'].'-'.$line['occupancy'], $primaries, true) : null,
                    'rate_type' => filled($line['rate_type'] ?? null) ? $line['rate_type'] : null,
                ]);
            }

            $next = $resolved === [] ? ChannelConnectionStatus::Draft : ChannelConnectionStatus::Mapped;

            if ($connection->status->canTransitionTo($next)) {
                $connection->update(['status' => $next, 'last_error' => null]);
            }
        });

        activity()
            ->performedOn($connection->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.mapped')
            ->withProperties(['connection_id' => $connection->id, 'mapped' => count($resolved)])
            ->log('OTA mapping saved');

        return ChannelActionResult::ok(trans_choice(':count line mapped for :channel.|:count lines mapped for :channel.', count($resolved), ['channel' => $connection->otaChannel->name]));
    }

    /**
     * The line that is primary for each OTA room and rate: the flagged one with the highest occupancy, else the highest
     * occupancy of the group. Each is `{rate plan mapping id}-{occupancy}`.
     *
     * @param  list<array<string, mixed>>  $resolved
     * @return list<string>
     */
    private function primaries(array $resolved): array
    {
        $groups = [];

        foreach ($resolved as $line) {
            $groups["{$line['roomCode']}|{$line['rateCode']}"][] = $line;
        }

        return array_values(array_map(function (array $group): string {
            usort($group, fn (array $a, array $b): int => [(bool) ($b['is_primary'] ?? false), $b['occupancy']] <=> [(bool) ($a['is_primary'] ?? false), $a['occupancy']]);

            return $group[0]['rate_plan_mapping_id'].'-'.$group[0]['occupancy'];
        }, $groups));
    }
}
