<?php

namespace App\Services\Facilities;

use App\Data\Facilities\ChargeToOptionData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Facility;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioRouter;
use App\Services\Folio\FolioStatement;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Charge to choices of a reservation's facility booking: its Open folios (Master, each unit folio, each
 * third-party folio), and each unit that has no folio yet. A choice is `f{folio id}` or `u{unit id}`.
 * See docs/33-facilities/04-facility-bookings.md#charge-to.
 */
class ReservationChargeTo
{
    private const array FOLIO_TYPES = [FolioType::Master, FolioType::Unit, FolioType::ThirdParty];

    public function __construct(
        private FolioRouter $router,
        private FolioStatement $statement,
    ) {}

    /**
     * @param  array<int, string>  $unitLabels  Unit label by unit id
     * @return list<ChargeToOptionData>
     */
    public function options(Reservation $reservation, array $unitLabels): array
    {
        $folios = $this->folios($reservation)->where('status', FolioStatus::Open);
        $withFolio = $folios->where('type', FolioType::Unit)->pluck('reservation_unit_id')->all();
        $options = $folios
            ->sortBy(fn (Folio $folio): array => [match ($folio->type) {
                FolioType::Master => 0,
                FolioType::Unit => 1,
                default => 2,
            }, $folio->id])
            ->map(fn (Folio $folio): ChargeToOptionData => new ChargeToOptionData('f'.$folio->id, $this->statement->optionLabel($folio, $unitLabels)))
            ->values();

        $newUnits = $this->liveUnits($reservation)
            ->reject(fn (ReservationUnit $unit): bool => in_array($unit->id, $withFolio, true))
            ->map(fn (ReservationUnit $unit): ChargeToOptionData => new ChargeToOptionData('u'.$unit->id, __('Unit :unit (new folio)', ['unit' => $unitLabels[$unit->id] ?? '—'])));

        return array_values($options->concat($newUnits)->all());
    }

    /**
     * Where the facility's charge goes by the routing rules, for the first live unit: a folio, or that unit when it has
     * no folio yet. Null when the reservation has no live unit or the routed folio is not Open.
     */
    public function defaultFor(Reservation $reservation, Facility $facility): ?string
    {
        $unit = $this->liveUnits($reservation)->first();

        if ($unit === null) {
            return null;
        }

        $facility->loadMissing('chargeItem.transactionCode');
        $folio = $this->router->targetFor($unit, $facility->chargeItem->transactionCode);

        if ($folio === null) {
            return 'u'.$unit->id;
        }

        return $folio->status === FolioStatus::Open ? 'f'.$folio->id : null;
    }

    /**
     * The folio a choice names, or the unit whose folio is to be created. A unit that already has a folio gives that
     * folio. Null when the choice is not of this reservation (a voided or event folio, another reservation's folio, a
     * cancelled unit): `folio_not_of_owner`. The folio's status is the caller's check.
     *
     * @return array{folio: Folio|null, unit: ReservationUnit|null}|null
     */
    public function resolve(Reservation $reservation, ?string $choice): ?array
    {
        if ($choice === null || preg_match('/^([fu])(\d+)$/', $choice, $match) !== 1) {
            return null;
        }

        if ($match[1] === 'f') {
            $folio = $this->folios($reservation)->firstWhere('id', (int) $match[2]);

            return $folio === null ? null : ['folio' => $folio, 'unit' => null];
        }

        $unit = $this->liveUnits($reservation)->firstWhere('id', (int) $match[2]);

        if ($unit === null) {
            return null;
        }

        $folio = $this->router->unitFolio($unit);

        return $folio === null ? ['folio' => null, 'unit' => $unit] : ['folio' => $folio, 'unit' => null];
    }

    /**
     * The reservation's folios that can take a facility charge: not Voided, not an event folio.
     *
     * @return Collection<int, Folio>
     */
    private function folios(Reservation $reservation): Collection
    {
        return Folio::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('type', array_map(fn (FolioType $type): string => $type->value, self::FOLIO_TYPES))
            ->where('status', '!=', FolioStatus::Voided->value)
            ->with(['payerGuest', 'payerCompany:id,name'])
            ->get();
    }

    /**
     * Units that are not Cancelled or No-show, in unit order.
     *
     * @return Collection<int, ReservationUnit>
     */
    private function liveUnits(Reservation $reservation): Collection
    {
        return $reservation->units()
            ->whereNotIn('status', [UnitStatus::Cancelled->value, UnitStatus::NoShow->value])
            ->orderBy('id')
            ->get();
    }
}
