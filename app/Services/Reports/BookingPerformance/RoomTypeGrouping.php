<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\RoomType;

/**
 * By room type. Every unit has one.
 */
class RoomTypeGrouping extends NamedRecordGrouping
{
    protected function column(): string
    {
        return 'room_type_id';
    }

    protected function model(): string
    {
        return RoomType::class;
    }

    public function noneLabel(): string
    {
        return __('No room type');
    }
}
