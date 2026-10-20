<?php

namespace App\Actions\Channels;

use App\Enums\AriChangeKind;
use App\Enums\ChannelPropertyStatus;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Channels\ChannelAriRanges;
use App\Services\Channels\ChannelAriValues;
use App\Services\Channels\ChannelAriWindow;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FlushChannelAri
{
    /** The longest wait after repeated failures, in seconds. */
    public const MAX_BACKOFF = 900;

    public function __construct(
        private ChannelAriWindow $window,
        private ChannelAriValues $values,
        private ChannelAriRanges $ranges,
        private ChannelDriverManager $drivers,
    ) {}

    /**
     * Send the waiting changes of a property: one rates and restrictions call and one availability call, with ranges
     * merged and equal consecutive dates joined. Values are calculated now, never read from a copy. A full run first
     * adds the next 500 days of every mapped room type and pair, sends them with the waiting changes (also the ones
     * that failed before) and notes the time.
     *
     * Returns the seconds to wait before trying again when Channex cannot take a call now. The changes it could not
     * send stay waiting, the property is paused for that time and the wait grows with each failure. A refusal keeps
     * its error on the changes, which then wait for a retry or a full run. Returns null when nothing is left to wait for.
     */
    public function __invoke(ChannelProperty $channelProperty, bool $full = false): ?int
    {
        if ($channelProperty->status !== ChannelPropertyStatus::Synced) {
            return null;
        }

        if ($channelProperty->paused_until?->isFuture()) {
            return (int) now()->diffInSeconds($channelProperty->paused_until, true) + 1;
        }

        $channelProperty->loadMissing('property.settings');

        if ($full) {
            $this->addFullRange($channelProperty);
        }

        $changes = ChannelAriChange::query()
            ->where('channel_property_id', $channelProperty->id)
            ->waiting()
            ->when(! $full, fn ($query) => $query->whereNull('last_error'))
            ->get();

        if ($changes->isEmpty()) {
            return null;
        }

        [$first, $last] = $this->window->forProperty($channelProperty->property);
        [$stale, $changes] = $changes->partition(fn (ChannelAriChange $change): bool => $change->date_to->lt($first) || $change->date_from->gt($last));
        $this->markSent($stale, null);
        $driver = $this->drivers->forProvider($channelProperty->provider);
        $currency = $channelProperty->property->settings->currency->getCurrencyCode();
        $retryAfter = null;

        foreach ([
            [$changes->filter(fn (ChannelAriChange $change): bool => $change->kind->isRestrictionCall()), $this->restrictionValues($channelProperty, $currency, $first, $last), $driver->pushRestrictions(...)],
            [$changes->filter(fn (ChannelAriChange $change): bool => ! $change->kind->isRestrictionCall()), $this->availabilityValues($channelProperty, $first, $last), $driver->pushAvailability(...)],
        ] as [$part, $build, $push]) {
            if ($part->isNotEmpty()) {
                $retryAfter = max($retryAfter ?? 0, $this->send($channelProperty, $part, $build, $push) ?? 0) ?: null;
            }
        }

        if ($retryAfter === null && $full) {
            $channelProperty->update(['full_synced_at' => now()]);
        }

        return $retryAfter;
    }

    /**
     * Send one call, then mark its changes. Returns the wait in seconds when Channex cannot take it now.
     *
     * @param  Collection<int, ChannelAriChange>  $changes
     * @param  Closure(Collection<int, ChannelAriChange>): list<array<string, mixed>>  $build
     * @param  Closure(list<array<string, mixed>>): ?string  $push
     */
    private function send(ChannelProperty $channelProperty, Collection $changes, Closure $build, Closure $push): ?int
    {
        try {
            $values = $build($changes);
            $taskId = $values === [] ? null : $push($values);
        } catch (ChannexRetryLater $exception) {
            return $this->defer($channelProperty, $changes, $exception);
        } catch (ChannexRequestFailed $exception) {
            $this->refuse($channelProperty, $changes, $exception);

            return null;
        }

        $this->markSent($changes, $taskId);
        $channelProperty->update(['changes_sent_at' => now(), 'paused_until' => null, 'last_error' => null]);

        return null;
    }

    /**
     * @param  Collection<int, ChannelAriChange>  $changes
     */
    private function defer(ChannelProperty $channelProperty, Collection $changes, ChannexRetryLater $exception): int
    {
        ChannelAriChange::query()->whereKey($changes->pluck('id')->all())->update(['attempts' => DB::raw('attempts + 1')]);
        $attempts = (int) $changes->max('attempts') + 1;
        $wait = min(self::MAX_BACKOFF, max($exception->retryAfter, 60 * 2 ** ($attempts - 1)));
        $channelProperty->update(['paused_until' => now()->addSeconds($wait), 'last_error' => $exception->getMessage()]);

        return $wait;
    }

    /**
     * @param  Collection<int, ChannelAriChange>  $changes
     */
    private function refuse(ChannelProperty $channelProperty, Collection $changes, ChannexRequestFailed $exception): void
    {
        $message = $exception->errors === [] ? $exception->getMessage() : $exception->getMessage().' '.json_encode($exception->errors, JSON_UNESCAPED_UNICODE);
        ChannelAriChange::query()->whereKey($changes->pluck('id')->all())->update(['attempts' => DB::raw('attempts + 1'), 'last_error' => $message]);
        $channelProperty->update(['last_error' => $message]);
    }

    /**
     * @param  Collection<int, ChannelAriChange>  $changes
     */
    private function markSent(Collection $changes, ?string $taskId): void
    {
        if ($changes->isNotEmpty()) {
            ChannelAriChange::query()->whereKey($changes->pluck('id')->all())->update(['sent_at' => now(), 'task_id' => $taskId, 'last_error' => null]);
        }
    }

    /**
     * One line for each mapped room type (availability) and pair (rates), for the whole window.
     */
    private function addFullRange(ChannelProperty $channelProperty): void
    {
        [$first, $last] = $this->window->forProperty($channelProperty->property);
        $rows = [];

        foreach (ChannelRoomTypeMapping::query()->where('channel_property_id', $channelProperty->id)->pluck('room_type_id') as $roomTypeId) {
            $rows[] = [$roomTypeId, null, AriChangeKind::Availability];
        }

        foreach (ChannelRatePlanMapping::query()->where('channel_property_id', $channelProperty->id)->get(['room_type_id', 'rate_plan_id']) as $mapping) {
            $rows[] = [$mapping->room_type_id, $mapping->rate_plan_id, AriChangeKind::Rates];
        }

        foreach ($rows as [$roomTypeId, $ratePlanId, $kind]) {
            $waiting = ChannelAriChange::query()
                ->where('channel_property_id', $channelProperty->id)
                ->where('room_type_id', $roomTypeId)
                ->where('rate_plan_id', $ratePlanId)
                ->where('kind', $kind)
                ->waiting()
                ->oldest('id')
                ->first();

            if ($waiting === null) {
                ChannelAriChange::query()->create([
                    'channel_property_id' => $channelProperty->id,
                    'room_type_id' => $roomTypeId,
                    'rate_plan_id' => $ratePlanId,
                    'kind' => $kind,
                    'date_from' => $first->toDateString(),
                    'date_to' => $last->toDateString(),
                ]);

                continue;
            }

            $waiting->update(['date_from' => min($waiting->date_from, $first)->toDateString(), 'date_to' => max($waiting->date_to, $last)->toDateString(), 'last_error' => null]);
        }
    }

    /**
     * The `/restrictions` values for the waiting rate lines, merged by pair and compressed to ranges.
     *
     * @return Closure(Collection<int, ChannelAriChange>): list<array<string, mixed>>
     */
    private function restrictionValues(ChannelProperty $channelProperty, string $currency, CarbonImmutable $first, CarbonImmutable $last): Closure
    {
        return function (Collection $changes) use ($channelProperty, $currency, $first, $last): array {
            $mappings = ChannelRatePlanMapping::query()->where('channel_property_id', $channelProperty->id)->get()
                ->keyBy(fn (ChannelRatePlanMapping $mapping): string => "{$mapping->room_type_id}:{$mapping->rate_plan_id}");
            $roomTypes = RoomType::query()->whereKey($changes->pluck('room_type_id')->unique()->all())->active()->get()->keyBy('id');
            $plans = RatePlan::query()->whereKey($changes->pluck('rate_plan_id')->unique()->all())->active()->get()->keyBy('id');
            $values = [];

            foreach ($changes->groupBy(fn (ChannelAriChange $change): string => "{$change->room_type_id}:{$change->rate_plan_id}") as $key => $group) {
                $mapping = $mappings->get($key);
                $roomType = $roomTypes->get($group->first()->room_type_id);
                $plan = $plans->get($group->first()->rate_plan_id);

                if ($mapping === null || $roomType === null || $plan === null) {
                    continue;
                }

                $dates = [];

                foreach ($this->ranges->merge($group->map(fn (ChannelAriChange $change): array => [$change->date_from->max($first)->toDateString(), $change->date_to->min($last)->toDateString()])->all()) as [$from, $to]) {
                    $dates += $this->values->restrictions($plan, $roomType, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $currency);
                }

                foreach ($this->ranges->compress($dates) as $range) {
                    $values[] = [
                        'property_id' => $channelProperty->external_property_id,
                        'rate_plan_id' => $mapping->external_rate_plan_id,
                        'date_from' => $range['from'],
                        'date_to' => $range['to'],
                        ...$range['values'],
                    ];
                }
            }

            return $values;
        };
    }

    /**
     * The `/availability` values for the waiting availability lines of all room types.
     *
     * @return Closure(Collection<int, ChannelAriChange>): list<array<string, mixed>>
     */
    private function availabilityValues(ChannelProperty $channelProperty, CarbonImmutable $first, CarbonImmutable $last): Closure
    {
        return function (Collection $changes) use ($channelProperty, $first, $last): array {
            $mappings = ChannelRoomTypeMapping::query()->where('channel_property_id', $channelProperty->id)->get()->keyBy('room_type_id');
            $roomTypes = RoomType::query()->whereKey($changes->pluck('room_type_id')->unique()->all())->active()->get()->keyBy('id');
            $values = [];

            foreach ($changes->groupBy('room_type_id') as $roomTypeId => $group) {
                $mapping = $mappings->get($roomTypeId);
                $roomType = $roomTypes->get($roomTypeId);

                if ($mapping === null || $roomType === null) {
                    continue;
                }

                $dates = [];

                foreach ($this->ranges->merge($group->map(fn (ChannelAriChange $change): array => [$change->date_from->max($first)->toDateString(), $change->date_to->min($last)->toDateString()])->all()) as [$from, $to]) {
                    $dates += $this->values->availability([$roomType], CarbonImmutable::parse($from), CarbonImmutable::parse($to))[$roomTypeId] ?? [];
                }

                foreach ($this->ranges->compress($dates) as $range) {
                    $values[] = [
                        'property_id' => $channelProperty->external_property_id,
                        'room_type_id' => $mapping->external_room_type_id,
                        'date_from' => $range['from'],
                        'date_to' => $range['to'],
                        'availability' => $range['values']['availability'],
                    ];
                }
            }

            return $values;
        };
    }
}
