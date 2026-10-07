<?php

namespace App\Enums;

/**
 * What the Check in form knows about tonight's room. `Auto` means no room yet but `CheckInUnit` will assign one,
 * because the property has `auto_assign_rooms` on. `None` blocks the form until staff assign a room.
 * See docs/18-front-desk/02-room-and-bed-assignment.md.
 */
enum CheckInRoomState: string
{
    case Assigned = 'assigned';
    case Auto = 'auto';
    case None = 'none';
}
