<?php

namespace App\Services\Folio;

use App\Actions\Folio\SaveOldFoliosResult;
use App\Data\Folio\OldFolioLineData;
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
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Payments\AvailablePaymentMethod;
use App\Services\Property\MigrationWindow;
use App\Services\Reservations\ReservationFormOptions;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Reads the old folio page: the Open folios of the reservation as tabs, the room nights drafted from the unit nights
 * before the go-live date, the items a row picks from (charge items, taxes and the Old system method), and, once the
 * first save is done, the saved lines read only. It writes nothing. See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
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
     * Whether the reservation already has lines copied from the old system.
     */
    public function hasMigratedLines(Reservation $reservation): bool
    {
        return FolioLine::query()->whereIn('folio_id', $reservation->folios()->select('folios.id'))->where('migrated', true)->exists();
    }

    /**
     * Expects the property's `settings` to be loaded.
     */
    public function build(User $user, Property $property, Reservation $reservation): OldFolioPageData
    {
        $businessDate = $this->currentBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has no open business date.');
        $goLive = $this->firstBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has not gone live.');
        $reservation->loadMissing('booker');
        $readOnly = $this->hasMigratedLines($reservation);
        $unitLabels = $this->labels->unitLabels($reservation);
        $folios = $reservation->folios()->where('status', FolioStatus::Open)->with(['payerGuest', 'payerCompany:id,name'])->orderBy('id')->get();
        $drafts = $readOnly ? [] : $this->roomNights($reservation, $folios, $goLive->toDateString());
        $saved = $readOnly ? $this->savedLines($folios) : [];

        return new OldFolioPageData(
            reservationId: $reservation->id,
            reference: $reservation->reference,
            guest: $reservation->booker->full_name,
            title: __('Copy old folio · :reference · :guest', ['reference' => $reservation->reference, 'guest' => $reservation->booker->full_name]),
            goLiveDate: $goLive->toDateString(),
            businessDate: $businessDate->toDateString(),
            currency: CurrencyData::forProperty($property),
            readOnly: $readOnly,
            canAddMaster: ! $reservation->folios()->where('type', FolioType::Master)->where('status', '!=', FolioStatus::Voided)->exists(),
            canViewCompany: $user->can(PermissionEnum::CompanyView->value),
            tabs: array_values($folios->map(fn (Folio $folio): OldFolioTabData => new OldFolioTabData(
                $folio->id,
                $this->labels->folioLabel($folio, $unitLabels),
                $folio->legacy_number,
                $drafts[$folio->id] ?? [],
                $saved[$folio->id]['lines'] ?? [],
                $readOnly ? ($saved[$folio->id]['total'] ?? '0.00') : null,
            ))->all()),
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
     * One draft row for each night before the go-live date, on the unit's own Open folio. The key is made from the
     * night, so a reload gives the same key.
     *
     * @param  iterable<Folio>  $folios
     * @return array<int, list<OldFolioRowData>>
     */
    private function roomNights(Reservation $reservation, iterable $folios, string $goLive): array
    {
        $unitFolios = [];

        foreach ($folios as $folio) {
            if ($folio->type === FolioType::Unit) {
                $unitFolios[$folio->reservation_unit_id] = $folio;
            }
        }

        $drafts = [];

        foreach ($reservation->units()->whereIn('id', array_keys($unitFolios))->orderBy('id')->get() as $unit) {
            $folio = $unitFolios[$unit->id];

            foreach ($unit->nights()->where('date', '<', $goLive)->orderBy('date')->get() as $night) {
                $drafts[$folio->id][] = new OldFolioRowData(
                    key: self::nightKey($night->id),
                    kind: OldFolioRowKind::Room,
                    serviceDate: $night->date->toDateString(),
                    folioId: $folio->id,
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

    /**
     * The saved lines of each folio, with their sum (a payment is negative).
     *
     * @param  iterable<Folio>  $folios
     * @return array<int, array{lines: list<OldFolioLineData>, total: string}>
     */
    private function savedLines(iterable $folios): array
    {
        $saved = [];

        foreach ($folios as $folio) {
            $lines = FolioLine::query()->where('folio_id', $folio->id)->where('migrated', true)->with('transactionCode:id,name')->orderBy('service_date')->orderBy('id')->get();
            $saved[$folio->id] = [
                'lines' => array_values($lines->map(fn (FolioLine $line): OldFolioLineData => new OldFolioLineData(
                    $line->id,
                    $line->service_date->toDateString(),
                    $line->kind === TransactionKind::Payment ? __('Old system') : $line->transactionCode->name,
                    $line->description,
                    $line->quantity,
                    (string) $line->amount,
                ))->all()),
                'total' => (string) $lines->reduce(fn (BigDecimal $sum, FolioLine $line): BigDecimal => $sum->plus((string) $line->amount), BigDecimal::zero())->toScale(2),
            ];
        }

        return $saved;
    }
}
