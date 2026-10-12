<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\PostingResult;
use App\Actions\Reservations\ChangeNightPrice;
use App\Models\NightAuditException;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\NightAudit\PackageNightRespread;
use Brick\Money\AllocationMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class FixPackageNightPrice
{
    public const REFUSAL_CHANGE_PRICE_REQUIRED = 'change_price_required';

    public function __construct(
        private PackageNightRespread $respread,
        private RetryRoomCharge $retryRoomCharge,
        private ChangeNightPrice $changeNightPrice,
    ) {}

    /**
     * Spread the stored prices of a package's uncharged nights by their inclusions, then retry the exception's
     * night in the same transaction. The stay total stays unchanged. When the uncharged nights cannot cover their
     * inclusions, the whole stay is spread again: each charged night whose price changes is corrected through
     * `ChangeNightPrice` (reversed and posted again under `night:{id}:fix:{n}`), which needs
     * `$canCorrectChargedNights` (the actor's `reservations.change-price`). Refusals are checked in order:
     * `exception_not_retried`, `not_package_night`, `package_below_inclusions`, `change_price_required`, then any
     * refusal from `ChangeNightPrice` or `RetryRoomCharge`. A refusal writes and logs nothing.
     * The exception row is locked first, then the unit and its nights, so two clicks resolve it once.
     * See docs/20-night-audit/02-readiness-panel.md#fix-price-of-a-package-night.
     */
    public function __invoke(User $actor, NightAuditException $exception, bool $canCorrectChargedNights = false): PostingResult
    {
        try {
            return DB::transaction(function () use ($actor, $exception, $canCorrectChargedNights): PostingResult {
                $locked = NightAuditException::query()->where('property_id', $exception->property_id)->lockForUpdate()->findOrFail($exception->id);

                if ($locked->reservation_unit_id !== null) {
                    ReservationUnit::query()->where('property_id', $locked->property_id)->lockForUpdate()->findOrFail($locked->reservation_unit_id);
                    $locked->reservationUnit->nights()->orderBy('date')->lockForUpdate()->get();
                }

                $spread = $this->respread->forException($locked);

                if ($spread->refusal !== null) {
                    return PostingResult::refused($spread->refusal);
                }

                if ($spread->wholeStay && ! $canCorrectChargedNights) {
                    return PostingResult::refused(self::REFUSAL_CHANGE_PRICE_REQUIRED);
                }

                $normalIndexes = array_keys(array_filter($spread->nights, fn (UnitNight $night): bool => $night->override_amount === null && ! $spread->isCharged($night)));
                $extra = array_reduce($normalIndexes, fn (Money $sum, int $index): Money => $sum->plus($spread->nights[$index]->extra_amount), Money::zero($spread->total->getCurrency()));
                $extras = $extra->isZero() ? array_fill(0, count($normalIndexes), $extra) : $extra->allocate(
                    array_map(fn (int $index): string => (string) $spread->amounts[$index]->getMinorAmount(), $normalIndexes),
                    AllocationMode::FloorToLargestRemainder,
                );
                $extraByIndex = $normalIndexes === [] ? [] : array_combine($normalIndexes, $extras);
                $changes = [];

                foreach ($spread->nights as $index => $night) {
                    $amount = $spread->amounts[$index];
                    $charged = $spread->isCharged($night);
                    $changes[] = ['unit_night_id' => $night->id, 'date' => $night->date->toDateString(), 'old_amount' => $night->amount, 'new_amount' => (string) $amount->getAmount(), 'charged' => $charged];

                    if ($charged) {
                        $this->correctChargedNight($actor, $night, $amount);

                        continue;
                    }

                    $values = ['amount' => (string) $amount->getAmount()];

                    if ($night->override_amount !== null) {
                        $values['override_amount'] = (string) $amount->getAmount();
                    } else {
                        $values['extra_amount'] = (string) $extraByIndex[$index]->getAmount();
                        $values['base_amount'] = (string) $amount->minus($extraByIndex[$index])->getAmount();
                    }

                    $night->forceFill($values)->save();
                }

                $result = ($this->retryRoomCharge)($actor, $locked);

                if ($result->isRefused()) {
                    throw new PackageNightPriceRefused($result);
                }

                activity()->performedOn($locked->reservationUnit->reservation)->causedBy($actor)
                    ->event('reservation.package_nights_respread')
                    ->withProperties(['property_id' => $locked->property_id, 'reservation_unit_id' => $locked->reservation_unit_id, 'night_audit_exception_id' => $locked->id, 'whole_stay' => $spread->wholeStay, 'nights' => $changes])
                    ->log('Package night prices spread again');

                return $result;
            });
        } catch (PackageNightPriceRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * Reverse a charged night and post it again at its new price, as Change price does. A night already at that
     * price is left alone. A refusal rolls the whole fix back.
     */
    private function correctChargedNight(User $actor, UnitNight $night, Money $amount): void
    {
        if ($amount->isEqualTo($night->amount)) {
            return;
        }

        $result = ($this->changeNightPrice)($actor, $night->reservationUnit, [$night], (string) $amount->getAmount(), __('Package price spread by inclusions'));

        if ($result->isRefused()) {
            throw new PackageNightPriceRefused(PostingResult::refused((string) $result->refusal));
        }
    }
}
