<?php

namespace App\Enums;

/**
 * Why a company user cannot accept their link. The Action returns it and writes nothing.
 */
enum CompanyLinkRefusal: string
{
    case NotPending = 'not_pending';
    case CompanySuspended = 'company_suspended';
    case PropertyNotLive = 'property_not_live';
    case LoginNotAvailable = 'login_not_available';
    case AlreadyLinked = 'already_linked';

    public function message(): string
    {
        return match ($this) {
            self::NotPending => __('This invitation is no longer open.'),
            self::CompanySuspended => __('This company is suspended. Ask the property to reactivate it.'),
            self::PropertyNotLive => __('This property is not open yet.'),
            self::LoginNotAvailable => __('This login is not available.'),
            self::AlreadyLinked => __('This invitation can no longer be accepted. Ask the property to send it again.'),
        };
    }
}
