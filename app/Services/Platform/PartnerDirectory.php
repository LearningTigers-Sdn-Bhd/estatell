<?php

namespace App\Services\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The admin list of partners for one status tab: newest first, with the login's email and temp password expiry
 * and the property count.
 */
class PartnerDirectory
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, Partner>
     */
    public function paginate(PartnerStatus $status): LengthAwarePaginator
    {
        return Partner::query()
            ->where('status', $status)
            ->withCount('properties')
            ->addSelect([
                'user_id' => User::query()->select('id')->whereColumn('partner_id', 'partners.id')->orderBy('id')->limit(1),
                'user_email' => User::query()->select('email')->whereColumn('partner_id', 'partners.id')->orderBy('id')->limit(1),
                'user_temporary_password_expires_at' => User::query()->select('temporary_password_expires_at')->whereColumn('partner_id', 'partners.id')->orderBy('id')->limit(1),
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The open partner invitations for the Invited tab, most recently sent first. Expired ones stay listed until
     * they are resent or revoked.
     *
     * @return LengthAwarePaginator<int, PartnerInvitation>
     */
    public function paginateInvitations(): LengthAwarePaginator
    {
        return PartnerInvitation::query()
            ->open()
            ->with(['partner', 'inviter'])
            ->orderByDesc('last_sent_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Every partner as a picker option for the admin property list filter, with "Direct" first. The value is the
     * partner id, or `direct` for properties with no partner.
     *
     * @return list<array{value: string, label: string}>
     */
    public function filterOptions(): array
    {
        return [
            ['value' => PropertyDirectory::DIRECT, 'label' => __('Direct')],
            ...Partner::query()->orderBy('name')->get(['id', 'name', 'code_prefix'])
                ->map(fn (Partner $partner): array => ['value' => (string) $partner->id, 'label' => "{$partner->name} ({$partner->code_prefix})"])
                ->all(),
        ];
    }

    /**
     * The Active partners a direct property can move to.
     *
     * @return list<array{value: string, label: string}>
     */
    public function moveOptions(): array
    {
        return array_values(Partner::query()->where('status', PartnerStatus::Active)->orderBy('name')->get(['id', 'name', 'code_prefix'])
            ->map(fn (Partner $partner): array => ['value' => (string) $partner->id, 'label' => "{$partner->name} ({$partner->code_prefix})"])
            ->all());
    }
}
