<?php

namespace App\Enums;

/**
 * Why a portal login email cannot be set on a company. The messages never name another property or company.
 */
enum PortalLoginRefusal: string
{
    case OtherKindOfAccount = 'other_kind_of_account';
    case TakenByOtherCompany = 'taken_by_other_company';
    case NotAvailable = 'not_available';
    case CompanySuspended = 'company_suspended';

    public function message(): string
    {
        return match ($this) {
            self::OtherKindOfAccount => __('This email belongs to another kind of estatell account.'),
            self::TakenByOtherCompany => __('This email is already the portal login of another company here.'),
            self::NotAvailable => __('This login is not available.'),
            self::CompanySuspended => __('Reactivate the company before you change its portal login.'),
        };
    }
}
