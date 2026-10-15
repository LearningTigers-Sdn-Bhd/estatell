<?php

namespace App\Actions\Guests;

use App\Data\Guests\GuestBulkResultData;
use App\Enums\GuestBulkAction;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkUpdateGuests
{
    /**
     * Each guest keeps its own transaction and audit entry. Expected refusals do not stop the batch.
     */
    public function __construct(
        private SetVip $setVip,
        private BlacklistGuest $blacklistGuest,
        private UnblacklistGuest $unblacklistGuest,
        private AnonymizeGuest $anonymizeGuest,
    ) {}

    /**
     * The caller resolves every ID within the property before calling this Action.
     *
     * @param  Collection<int, Guest>  $guests
     */
    public function __invoke(User $actor, Collection $guests, GuestBulkAction $action, ?string $reason = null): GuestBulkResultData
    {
        $changed = [];
        $unchanged = [];
        $skipped = [];

        foreach ($guests as $guest) {
            $result = DB::transaction(function () use ($actor, $guest, $action, $reason): ?bool {
                $guest = Guest::query()->where('property_id', $guest->property_id)->lockForUpdate()->find($guest->id);

                if ($guest === null || $guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                    return null;
                }

                $alreadySet = match ($action) {
                    GuestBulkAction::MarkVip => $guest->is_vip,
                    GuestBulkAction::UnmarkVip => ! $guest->is_vip,
                    GuestBulkAction::Blacklist => $guest->blacklisted_at !== null,
                    GuestBulkAction::Unblacklist => $guest->blacklisted_at === null,
                    GuestBulkAction::Anonymize => false,
                };

                if ($alreadySet) {
                    return false;
                }

                $didChange = match ($action) {
                    GuestBulkAction::MarkVip => ($this->setVip)($actor, $guest, true),
                    GuestBulkAction::UnmarkVip => ($this->setVip)($actor, $guest, false),
                    GuestBulkAction::Blacklist => ($this->blacklistGuest)($actor, $guest, (string) $reason),
                    GuestBulkAction::Unblacklist => ($this->unblacklistGuest)($actor, $guest),
                    GuestBulkAction::Anonymize => ($this->anonymizeGuest)($actor, $guest),
                };

                return $didChange ? true : null;
            });

            if ($result === true) {
                $changed[] = $guest->id;
            } elseif ($result === false) {
                $unchanged[] = $guest->id;
            } else {
                $skipped[] = $guest->id;
            }
        }

        return new GuestBulkResultData($changed, $unchanged, $skipped);
    }
}
