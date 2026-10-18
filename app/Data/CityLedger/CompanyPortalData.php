<?php

namespace App\Data\CityLedger;

use App\Data\Shared\BadgeData;
use App\Enums\CompanyUserStatus;
use App\Models\CompanyUser;
use Spatie\LaravelData\Data;

/**
 * A company's portal login for the Portal form on the Details tab. It never carries a password: Copy message fetches
 * the text from its own route.
 */
class CompanyPortalData extends Data
{
    public function __construct(
        public ?string $email,
        public ?int $cancelDays,
        public ?BadgeData $status,
        public bool $hasTempPassword,
        public bool $tempPasswordExpired,
        public ?int $tempPasswordDaysLeft,
        public bool $canResend,
        public bool $canReset,
        public bool $isDisabled,
    ) {}

    /**
     * @param  CompanyUser|null  $link  The open link with `user` loaded.
     * @param  bool  $linkedElsewhere  The login has an Active link at another property, so a reset would lock it out.
     */
    public static function fromLink(?CompanyUser $link, ?int $cancelDays, bool $linkedElsewhere, bool $isDisabled): self
    {
        if (! $link instanceof CompanyUser) {
            return new self(null, $cancelDays, null, false, false, null, false, false, $isDisabled);
        }

        $user = $link->user;
        $hasTemp = $link->status === CompanyUserStatus::Active && $user->temporary_password !== null;
        $expired = $hasTemp && $user->temporary_password_expires_at->isPast();

        return new self(
            email: $user->email,
            cancelDays: $cancelDays,
            status: new BadgeData($link->status->value, $link->status->label(), $link->status->tone()),
            hasTempPassword: $hasTemp && ! $expired,
            tempPasswordExpired: $expired,
            tempPasswordDaysLeft: $hasTemp && ! $expired ? (int) ceil(now()->diffInDays($user->temporary_password_expires_at, true)) : null,
            canResend: $link->status === CompanyUserStatus::Pending && $user->deactivated_at === null,
            canReset: $link->status === CompanyUserStatus::Active && $user->must_change_password && $user->deactivated_at === null && ! $linkedElsewhere,
            isDisabled: $isDisabled,
        );
    }
}
