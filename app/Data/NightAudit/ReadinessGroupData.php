<?php

namespace App\Data\NightAudit;

use App\Enums\AuditCheck;
use App\Enums\AuditExceptionType;
use App\Enums\AuditFix;
use App\Models\Property;
use App\Services\NightAudit\ReadinessAdvice;
use App\Services\NightAudit\ReadinessItem;
use Spatie\LaravelData\Data;

/**
 * One row of the night audit sheet: every unit of one reservation that has the same problem, so a group booking is one
 * row, not one for each unit. A reservation with several units can be opened to see them one by one.
 */
class ReadinessGroupData extends Data
{
    /**
     * @param  string  $headline  What is wrong, for example "4 units not checked in".
     * @param  string|null  $consequence  What the audit does with the row if nobody touches it.
     * @param  list<string>  $reasons  Why an earlier audit left the units, one for each different reason.
     * @param  list<ReadinessActionData>  $actions
     * @param  list<ReadinessUnitData>  $units  Empty for a reservation with one unit.
     * @param  bool  $openUnits  The units are the way to fix the row, so the sheet shows them open.
     */
    public function __construct(
        public AuditCheck $check,
        public bool $blocker,
        public int $reservationId,
        public string $reference,
        public string $guest,
        public string $headline,
        public string $dates,
        public ?string $consequence,
        public array $reasons,
        public array $actions,
        public array $units,
        public bool $openUnits = false,
    ) {}

    /**
     * @param  non-empty-list<ReadinessItem>  $items  Items of one check and one reservation.
     */
    public static function fromItems(array $items, Property $property, ReadinessAdvice $advice, bool $canCheckIn, bool $canCheckOut): self
    {
        $first = $items[0];
        $check = $first->check;
        $count = count($items);
        $unitIds = array_values(array_filter(array_map(fn (ReadinessItem $item): int => (int) $item->reservationUnitId, $items)));
        $dates = array_values(array_unique(array_map(fn (ReadinessItem $item): string => $item->detail, $items)));

        return new self(
            check: $check,
            blocker: $check->isBlocker(),
            reservationId: $first->reservationId,
            reference: $first->reference,
            guest: $first->guest ?? $first->reference,
            headline: $count === 1 ? $check->label() : __(':count units — :check', ['count' => $count, 'check' => mb_strtolower($check->label())]),
            dates: count($dates) === 1 ? $dates[0] : '',
            consequence: $advice->consequence($check, $property, $first->dayUse),
            reasons: $check === AuditCheck::CarriedException
                ? array_values(array_unique(array_map(fn (ReadinessItem $item): string => $advice->reason((string) $item->message), $items)))
                : [],
            actions: [
                ...($count === 1 ? self::exceptionActions($first, $property) : []),
                ...self::actions($check, $property, $first->reservationId, $count, $unitIds, $advice, null, true, $canCheckIn, $canCheckOut, $first->folioId, array_any($items, fn (ReadinessItem $item): bool => $item->canExtend)),
            ],
            units: $count === 1 ? [] : array_map(fn (ReadinessItem $item): ReadinessUnitData => new ReadinessUnitData(
                unitId: (int) $item->reservationUnitId,
                guest: $item->guest ?? $item->reference,
                detail: $item->detail,
                reason: $item->message === null ? null : $advice->reason($item->message),
                actions: [
                    ...self::exceptionActions($item, $property),
                    ...self::actions($check, $property, $item->reservationId, 1, [(int) $item->reservationUnitId], $advice, (int) $item->reservationUnitId, false, $canCheckIn, $canCheckOut, null, $item->canExtend),
                ],
            ), $items),
            openUnits: $check === AuditCheck::DepartureNotCheckedOut && $count > 1,
        );
    }

    /**
     * @return list<ReadinessActionData>
     */
    private static function exceptionActions(ReadinessItem $item, Property $property): array
    {
        if ($item->exceptionId === null) {
            return [];
        }

        return [
            ...($item->canFixPrice ? [ReadinessActionData::fixPrice($property, $item->exceptionId)]
                : ($item->exceptionType === AuditExceptionType::RoomCharge ? [ReadinessActionData::retry($property, $item->exceptionId)] : [])),
            ReadinessActionData::dismiss($property, $item->exceptionId),
        ];
    }

    /**
     * @param  list<int>  $unitIds
     * @return list<ReadinessActionData>
     */
    private static function actions(AuditCheck $check, Property $property, int $reservationId, int $count, array $unitIds, ReadinessAdvice $advice, ?int $unitId, bool $isGroup, bool $canCheckIn, bool $canCheckOut, ?int $folioId = null, bool $canExtend = false): array
    {
        $parameters = ['property' => $property->code, 'reservation' => $reservationId];
        $reservationUrl = route('reservations.show', $parameters, absolute: false);
        $many = $count > 1;
        $checkIn = match (true) {
            ! $canCheckIn => $isGroup ? ReadinessActionData::link(__('Check in'), $reservationUrl, 'log-in') : null,
            $isGroup && $many => ReadinessActionData::dialog('check-in-all', __('Check in all'), $property, $reservationId),
            default => ReadinessActionData::dialog('check-in', __('Check in'), $property, $reservationId, $unitId ?? $unitIds[0] ?? null),
        };
        $checkOut = match (true) {
            ! $canCheckOut => $isGroup ? ReadinessActionData::link(__('Check out'), $reservationUrl, 'log-out') : null,
            default => ReadinessActionData::dialog('check-out', __('Check out'), $property, $reservationId, $unitId ?? $unitIds[0] ?? null),
        };

        return match ($check) {
            AuditCheck::InHouseWithoutRoom => [
                ReadinessActionData::fix(AuditFix::Assign, $many ? __('Assign rooms') : __('Assign a room'), $property, $reservationId, $unitId),
            ],
            AuditCheck::ArrivalNotCheckedIn => [
                ...($checkIn === null ? [] : [$checkIn]),
                ReadinessActionData::fix(AuditFix::NoShow, $many ? __('Mark all no-show') : __('Mark no-show'), $property, $reservationId, $unitId, self::noShowConfirm($advice->noShowFee($property, $unitIds), $count)),
            ],
            AuditCheck::DepartureNotCheckedOut => [
                ...($checkOut === null || ($isGroup && $many) ? [] : [$checkOut]),
                ...($isGroup && $many && $canCheckOut ? [ReadinessActionData::link(__('Check out all'), route('reservations.check-out', $parameters, absolute: false), 'log-out')] : []),
                ...($canExtend ? [ReadinessActionData::fix(AuditFix::ExtendOneNight, __('Extend 1 night'), $property, $reservationId, $unitId, __('Move the departure one day later? One more night is charged.'))] : []),
            ],
            AuditCheck::FolioOpenAfterCheckOut => $isGroup ? [ReadinessActionData::link(__('Open folio'), route('reservations.folio', $parameters, absolute: false), 'receipt')] : [],
            AuditCheck::MasterNotBilled, AuditCheck::MoneyHeldOnClosedBooking => $isGroup ? [ReadinessActionData::link(__('Open folio'), route('reservations.folio', [...$parameters, 'folio' => $folioId], absolute: false), 'receipt')] : [],
            AuditCheck::CarriedException => $isGroup ? [ReadinessActionData::link(__('Open'), $reservationUrl)] : [],
        };
    }

    private static function noShowConfirm(?string $fee, int $count): string
    {
        $what = $count > 1 ? __('Mark :count units as no-show?', ['count' => $count]) : __('Mark this unit as no-show?');

        return $fee === null
            ? $what.' '.__('No fee is charged.')
            : $what.' '.__('The no-show fee of :fee is charged.', ['fee' => $fee]);
    }
}
