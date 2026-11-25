<?php

namespace App\Enums;

/**
 * What a POS PIN check or terminal sign-in decided. Everything but Verified is a refusal the cashier reads.
 */
enum PosPinOutcome: string
{
    case Verified = 'verified';
    case InvalidPin = 'invalid_pin';
    case Locked = 'pin_locked';
    case NoPin = 'no_pin';
    case PinUpgradeRequired = 'pin_upgrade_required';
    case SelfApprovalNotAllowed = 'self_approval_not_allowed';
    case ApproverLacksPermission = 'approver_lacks_permission';
    case TerminalModeRequired = 'terminal_mode_required';

    public function label(): string
    {
        return match ($this) {
            self::Verified => __('PIN accepted.'),
            self::InvalidPin => __('That PIN is not right.'),
            self::Locked => __('Too many wrong PINs. Try again later.'),
            self::NoPin => __('This person has not set a POS PIN.'),
            self::PinUpgradeRequired => __('This role needs a 6-digit PIN. Set a new PIN in your account first.'),
            self::SelfApprovalNotAllowed => __('A manager other than you must approve this.'),
            self::ApproverLacksPermission => __('That person cannot approve this.'),
            self::TerminalModeRequired => __('Start Terminal Mode on this device first.'),
        };
    }
}
