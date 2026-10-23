<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Guests\AddGuestNote;
use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\BlacklistGuest;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\SetVip;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysGuestRow;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Illuminate\Support\Facades\DB;

/**
 * Writes one estatell guest for each row of the bundle's `guests.csv`. Guests already linked are skipped. Nothing is
 * merged: the replay attaches bookings by wastays guest id.
 */
class ImportWastaysGuests
{
    public function __construct(
        private CreateGuest $createGuest,
        private AddIdentityDocument $addIdentityDocument,
        private SetVip $setVip,
        private BlacklistGuest $blacklistGuest,
        private AddGuestNote $addGuestNote,
        private RecordImportLink $recordImportLink,
        private ImportLinks $importLinks,
        private CountryOptions $countries,
        private IdentityNumber $identityNumber,
    ) {}

    /**
     * Counts only. A dry run does everything and rolls back.
     *
     * @return array<string, int>
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): array
    {
        $counts = [
            'created' => 0,
            'already linked' => 0,
            'invalid emails dropped' => 0,
            'phones kept as notes' => 0,
            'unmatched countries' => 0,
            'ID documents: MyKad' => 0,
            'ID documents: Passport' => 0,
            'ID documents: Other' => 0,
            'VIP' => 0,
            'blacklisted' => 0,
        ];

        DB::beginTransaction();

        try {
            foreach ($target->bundle->rows('guests') as $source) {
                $sourceId = (string) $source['id'];

                if ($this->importLinks->has($target->property, 'guests', $sourceId)) {
                    $counts['already linked']++;

                    continue;
                }

                $row = new WastaysGuestRow($source, $this->countries, $this->identityNumber);

                $guest = ($this->createGuest)($target->actor, $target->property, $row->attributes);
                ($this->recordImportLink)($target->property, 'guests', $sourceId, $guest);
                $counts['created']++;

                $counts['invalid emails dropped'] += $row->emailDropped ? 1 : 0;
                $counts['unmatched countries'] += $row->unmatchedCountries;

                if ($row->unreadablePhone !== null) {
                    ($this->addGuestNote)($target->actor, $guest, 'wastays phone: '.$row->unreadablePhone);
                    $counts['phones kept as notes']++;
                }

                if ($row->document !== null) {
                    ($this->addIdentityDocument)($target->actor, $guest, $row->document);
                    $counts['ID documents: '.$row->document->type->name]++;
                }

                if ($row->isVip && ($this->setVip)($target->actor, $guest, true)) {
                    $counts['VIP']++;
                }

                if ($row->isBlacklisted && ($this->blacklistGuest)($target->actor, $guest, 'Blacklisted in wastays')) {
                    $counts['blacklisted']++;
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $counts;
    }
}
