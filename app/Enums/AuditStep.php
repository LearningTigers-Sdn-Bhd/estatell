<?php

namespace App\Enums;

use App\Actions\NightAudit\Steps\AuditStepRunner;
use App\Actions\NightAudit\Steps\CloseAndOpenNext;
use App\Actions\NightAudit\Steps\PostRoomChargesStep;
use App\Actions\NightAudit\Steps\PostScheduledAddOnsStep;
use App\Actions\NightAudit\Steps\ResolveMissedArrivals;
use App\Actions\NightAudit\Steps\ResolveOverdueDepartures;
use App\Actions\NightAudit\Steps\UpdateRoomStatuses;

/**
 * The steps of a night audit, in the order they run. `ClaimBusinessDate` is the claim itself and has no runner.
 * See docs/20-night-audit/03-audit-run.md.
 */
enum AuditStep: string
{
    case ClaimBusinessDate = 'claim_business_date';
    case ResolveMissedArrivals = 'resolve_missed_arrivals';
    case ResolveOverdueDepartures = 'resolve_overdue_departures';
    case PostRoomCharges = 'post_room_charges';
    case PostScheduledAddOns = 'post_scheduled_add_ons';
    case UpdateRoomStatuses = 'update_room_statuses';
    case CloseAndOpenNext = 'close_and_open_next';

    public function label(): string
    {
        return match ($this) {
            self::ClaimBusinessDate => __('Claim the business date'),
            self::ResolveMissedArrivals => __('Resolve missed arrivals'),
            self::ResolveOverdueDepartures => __('Resolve overdue departures'),
            self::PostRoomCharges => __('Post room charges'),
            self::PostScheduledAddOns => __('Post scheduled add-ons'),
            self::UpdateRoomStatuses => __('Update room statuses'),
            self::CloseAndOpenNext => __('Close the date and open the next'),
        };
    }

    /**
     * The class that runs the step, or null for the claim.
     *
     * @return class-string<AuditStepRunner>|null
     */
    public function runner(): ?string
    {
        return match ($this) {
            self::ClaimBusinessDate => null,
            self::ResolveMissedArrivals => ResolveMissedArrivals::class,
            self::ResolveOverdueDepartures => ResolveOverdueDepartures::class,
            self::PostRoomCharges => PostRoomChargesStep::class,
            self::PostScheduledAddOns => PostScheduledAddOnsStep::class,
            self::UpdateRoomStatuses => UpdateRoomStatuses::class,
            self::CloseAndOpenNext => CloseAndOpenNext::class,
        };
    }

    /**
     * The steps with a runner, in order.
     *
     * @return list<self>
     */
    public static function runnable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $step): bool => $step->runner() !== null));
    }
}
