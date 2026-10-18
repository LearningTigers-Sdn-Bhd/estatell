<?php

namespace App\Enums;

/**
 * What saving a company's portal login email did.
 */
enum PortalLoginOutcome: string
{
    case Unchanged = 'unchanged';
    case Removed = 'removed';
    case Created = 'created';
    case Invited = 'invited';
    case Refused = 'refused';
}
