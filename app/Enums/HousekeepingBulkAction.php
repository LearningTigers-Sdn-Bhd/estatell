<?php

namespace App\Enums;

/**
 * What the bulk bar on the housekeeping board does to the ticked rooms.
 */
enum HousekeepingBulkAction: string
{
    case Assign = 'assign';
    case MarkClean = 'mark_clean';
    case MarkInspected = 'mark_inspected';
    case SetPriority = 'set_priority';
    case ClearPriority = 'clear_priority';
    case AddRemark = 'add_remark';
    case NeedsMaintenance = 'needs_maintenance';
}
