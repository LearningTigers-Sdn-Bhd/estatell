<?php

namespace App\Services\Folio;

use App\Actions\Folio\SaveOldFoliosResult;
use App\Data\Folio\OldFolioOptionData;
use App\Data\Folio\OldFolioPageData;
use App\Data\Folio\OldFolioRowData;
use App\Data\Folio\OldFolioTabData;
use App\Data\Property\CurrencyData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\OldFolioRowKind;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Payments\AvailablePaymentMethod;
use App\Services\Property\MigrationWindow;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Reads the old folio page: the Open folios of the reservation as tabs (and a tab for each unit that has no folio yet),
 * each with its saved lines as editable rows and the room nights not saved yet drafted from the unit nights before the
 * go-live date, and the items a row picks from (charge items, taxes and the Old system method). It writes nothing. See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
class OldFolioPage
{
    public function __construct(
        private ReservationFormOptions $options,
        private ManualFolioPage $manualPage,
        private MoveChargesPreview $labels,
        private MigrationWindow $migrationWindow,
        private FirstBusinessDate $firstBusinessDate,
        private CurrentBusinessDate $currentBusinessDate,
        private AvailablePaymentMethod $availablePaymentMethod,
        private OldFolioLines $lines,
    ) {}

    /**
     * Whether the user may open the page for the reservation: `folio.manual`, an open migration window and a unit that
     * arrives before the go-live date.
     */
    public function isAvailable(User $user, Property $property, Reservation $reservation): bool
    {
        if (! $user->can(PermissionEnum::FolioManual->value) || ! $this->migrationWindow->isOpen($property)) {
            return false;
        }

        $goLive = $this->firstBusinessDate->date($property);

        return $goLive !== null && $reservation->units()->where('arrival_date', '<', $goLive->toDateString())->exists();
    }

    /**
     * The checked-in or checked-out units that arrived before the go-live date and have no Unit folio yet. Their tab
     * shows with the folio id `-{unit id}`, and `SaveOldFolios` creates the folio when a row needs it.
     *
     * @return Collection<int, ReservationUnit>
     */
    public function unitsWithoutFolio(Reservation $reservation): Collection
    {
        $goLive = $this->firstBusinessDate->date($reservation->property);

        if ($goLive === null) {
            return new Collection;
        }

        $withFolio = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Unit)->pluck('reservation_unit_id')->all();

        return $reservation->units()
            ->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut])
            ->where('arrival_date', '<', $goLive->toDateString())
            ->whereNotIn('id', $withFolio)
            ->orderBy('id')
            ->get();
    }

    /**
     * Expects the property's `settings` to be loaded. It writes nothing: a unit without a folio gets a tab of its own.
     */
    public function build(User $user, Property $property, Reservation $reservation): OldFolioPageData
    {
        $businessDate = $this->currentBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has no open business date.');
        $goLive = $this->firstBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has not gone live.');
        $reservation->loadMissing('booker');
        $unitLabels = $this->labels->unitLabels($reservation);
        $folios = $reservation->folios()->where('status', FolioStatus::Open)->with(['payerGuest', 'payerCompany:id,name'])->orderBy('id')->get();
        $pending = $this->unitsWithoutFolio($reservation);
        $allFolioIds = $reservation->folios()->pluck('id')->all();
        $saved = collect($this->lines->live($property->id, $folios->modelKeys()))->groupBy(fn (array $entry): int => $entry['row']->folioId);
        $versions = $this->lines->versions($allFolioIds);
        $drafts = $this->roomNights($reservation, $folios, $pending, $goLive->toDateString(), $versions);
        $openings = $this->lines->openingBalances($property->id, $folios->modelKeys());

        $tabs = $folios->map(fn (Folio $folio): OldFolioTabData => new OldFolioTabData(
            $folio->id,
            $this->labels->folioLabel($folio, $unitLabels),
            $folio->legacy_number,
            array_values([...($saved->get($folio->id, collect()))->map(fn (array $entry): OldFolioRowData => $entry['row'])->all(), ...($drafts[$folio->id] ?? [])]),
            $openings[$folio->id] ?? null,
        ))->all();

        foreach ($pending as $unit) {
            $tabs[] = new OldFolioTabData(
                -$unit->id,
                trim(__('Unit :unit', ['unit' => $unitLabels[$unit->id] ?? '—']).' · '.$reservation->booker->full_name, ' ·'),
                null,
                $drafts[-$unit->id] ?? [],
                null,
            );
        }

        return new OldFolioPageData(
            reservationId: $reservation->id,
            reference: $reservation->reference,
            guest: $reservation->booker->full_name,
            title: __('Copy old folio · :reference · :guest', ['reference' => $reservation->reference, 'guest' => $reservation->booker->full_name]),
            goLiveDate: $goLive->toDateString(),
            businessDate: $businessDate->toDateString(),
            currency: CurrencyData::forProperty($property),
            canAddMaster: ! $reservation->folios()->where('type', FolioType::Master)->where('status', '!=', FolioStatus::Voided)->exists(),
            canViewCompany: $user->can(PermissionEnum::CompanyView->value),
            tabs: array_values($tabs),
            items: $this->items($user, $property),
            maxRows: SaveOldFoliosResult::MAX_ROWS,
            cancelUrl: route('reservations.folio', ['property' => $property, 'reservation' => $reservation]),
        );
    }

    /**
     * The charge items, the taxes and the Old system method a row picks from.
     *
     * @return list<OldFolioOptionData>
     */
    private function items(User $user, Property $property): array
    {
        $items = [];

        foreach ($this->options->chargeItems($property) as $item) {
            $items[] = new OldFolioOptionData('charge', $item->id, $item->name);
        }

        foreach ($this->manualPage->taxOptions($property) as $tax) {
            $items[] = new OldFolioOptionData('tax', $tax->id, $tax->name);
        }

        if ($this->availablePaymentMethod->oldSystemOffered($property, $user)) {
            foreach (PaymentMethod::query()->where('property_id', $property->id)->where('type', PaymentMethodType::OldSystem)->ordered()->get() as $method) {
                $items[] = new OldFolioOptionData('payment', $method->id, str_starts_with($method->name, 'Old system') ? $method->name : __('Old system · :name', ['name' => $method->name]));
            }
        }

        return $items;
    }

    /**
     * One draft row for each night before the go-live date that has never been saved, on the unit's own Open folio (or
     * the tab `-{unit id}` of a unit without one). The key is made from the night, so a reload gives the same key. A night
     * whose line was reversed does not come back as a draft.
     *
     * @param  iterable<Folio>  $folios
     * @param  Collection<int, ReservationUnit>  $pending
     * @param  array<string, int>  $versions  Lines each row key has had
     * @return array<int, list<OldFolioRowData>>
     */
    private function roomNights(Reservation $reservation, iterable $folios, Collection $pending, string $goLive, array $versions): array
    {
        $tabs = [];

        foreach ($folios as $folio) {
            if ($folio->type === FolioType::Unit) {
                $tabs[$folio->reservation_unit_id] = $folio->id;
            }
        }

        foreach ($pending as $unit) {
            $tabs[$unit->id] = -$unit->id;
        }

        $drafts = [];

        foreach ($reservation->units()->whereIn('id', array_keys($tabs))->orderBy('id')->get() as $unit) {
            $tab = $tabs[$unit->id];

            foreach ($unit->nights()->where('date', '<', $goLive)->orderBy('date')->get() as $night) {
                $key = self::nightKey($night->id);

                if (isset($versions[$key])) {
                    continue;
                }

                $drafts[$tab][] = new OldFolioRowData(
                    key: $key,
                    kind: OldFolioRowKind::Room,
                    serviceDate: $night->date->toDateString(),
                    folioId: $tab,
                    unitId: $unit->id,
                    quantity: 1,
                    amount: (string) $night->amount,
                );
            }
        }

        return $drafts;
    }

    /**
     * A UUID made from a unit night, so a drafted row keeps its key.
     */
    public static function nightKey(int $nightId): string
    {
        $hash = md5('old-folio-night:'.$nightId);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
