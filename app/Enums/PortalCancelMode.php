<?php

namespace App\Enums;

/**
 * What a company user can do to cancel a booking now: cancel it free (on or before the cut-off), ask the property to
 * (after it, or when the company has no cut-off), or nothing (the stay started, or it is not Confirmed).
 */
enum PortalCancelMode: string
{
    case Free = 'free';
    case Request = 'request';
    case Closed = 'closed';
}
