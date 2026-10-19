<?php

namespace App\Data\Rates;

use App\Data\Shared\BadgeData;
use App\Enums\CompanyUserStatus;
use App\Enums\Tone;
use App\Models\Company;
use Spatie\LaravelData\Data;

/**
 * A company listed on a rate plan, for the plan editor's Companies tab: its portal login status and its Front desk and
 * Portal switches.
 */
class RatePlanCompanyRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?BadgeData $login,
        public bool $hasActiveLogin,
        public bool $atDesk,
        public bool $inPortal,
    ) {}

    /**
     * Load the company's `portalLink.user` and read the row from the `companies` pivot of the plan.
     */
    public static function fromModel(Company $company): self
    {
        $link = $company->portalLink;
        $user = $link?->user;
        $active = $link?->status === CompanyUserStatus::Active;
        $temporary = $active && $user?->temporary_password !== null && ! $user->temporary_password_expires_at?->isPast();

        $login = match (true) {
            $link === null => null,
            $link->status === CompanyUserStatus::Pending => new BadgeData('waiting', __('Waiting'), Tone::Warning),
            $temporary => new BadgeData('temp_password', __('Temp password'), Tone::Info),
            default => new BadgeData('active', $link->status->label(), Tone::Success),
        };

        return new self(
            id: $company->id,
            name: $company->name,
            login: $login,
            hasActiveLogin: $active,
            atDesk: (bool) $company->pivot->at_desk,
            inPortal: (bool) $company->pivot->in_portal,
        );
    }
}
