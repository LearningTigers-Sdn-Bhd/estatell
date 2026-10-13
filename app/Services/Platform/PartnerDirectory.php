<?php

namespace App\Services\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
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
                'user_email' => User::query()->select('email')->whereColumn('partner_id', 'partners.id')->orderBy('id')->limit(1),
                'user_temporary_password_expires_at' => User::query()->select('temporary_password_expires_at')->whereColumn('partner_id', 'partners.id')->orderBy('id')->limit(1),
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }
}
