<?php

namespace App\Enums;

/**
 * What really happened to a Confirmed stay whose departure date is the business date. See `CheckInEndedStay` and
 * docs/18-front-desk/01-check-in-and-check-out.md#check-in-after-the-stay-ended.
 */
enum EndedStayChoice: string
{
    case ArrivedLastNight = 'arrived_last_night';
    case ArrivingToday = 'arriving_today';
}
