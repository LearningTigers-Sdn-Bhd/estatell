<?php

namespace App\Actions\Channels;

use App\Enums\AriChangeKind;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use App\Services\Channels\ChannelAriWindow;

class QueueChannelPairRefresh
{
    public function __construct(private ChannelAriWindow $window) {}

    /**
     * Send the rates of some room type and rate plan pairs again for the next 500 days, through the normal outbox: one
     * waiting line for each pair, merged into the next flush (one `/restrictions` call). Used when a pair is sent in
     * another shape, because the options Channex holds for it start at zero until prices arrive. It is not a full
     * sync, so the once-a-day full sync rule does not apply.
     *
     * @param  list<array{0: int, 1: int}>  $pairs  Room type id and rate plan id.
     */
    public function __invoke(ChannelProperty $channelProperty, array $pairs): void
    {
        [$first, $last] = $this->window->forProperty($channelProperty->property);

        foreach ($pairs as [$roomTypeId, $ratePlanId]) {
            ChannelAriChange::query()->create([
                'channel_property_id' => $channelProperty->id,
                'room_type_id' => $roomTypeId,
                'rate_plan_id' => $ratePlanId,
                'kind' => AriChangeKind::Rates,
                'date_from' => $first->toDateString(),
                'date_to' => $last->toDateString(),
            ]);
        }

        FlushChannelAriJob::dispatch($channelProperty->id)->delay(now()->addSeconds(random_int(10, 30)));
    }
}
