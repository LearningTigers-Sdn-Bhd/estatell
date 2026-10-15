<?php

use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;

function basicRatePayload(array $rows, array $overrides = []): array
{
    return [
        'weekend_nights' => 'fri_sat',
        'same_every_night' => false,
        'rows' => $rows,
        ...$overrides,
    ];
}

function basicRateRow(RoomType $roomType, string $priceBy = 'per_room', string $weekday = '180.00', ?string $weekend = '220.00'): array
{
    return ['room_type_id' => $roomType->id, 'price_by' => $priceBy, 'weekday' => $weekday, 'weekend' => $weekend];
}

function rateOn(RatePlan $plan, RoomType $roomType, string $date): ?string
{
    return Rate::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $roomType->id)->whereDate('date', $date)->value('amount');
}
