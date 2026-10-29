<?php

namespace App\Data\NightAudit;

use App\Enums\AuditStatus;
use App\Enums\BusinessDateStatus;
use App\Enums\PermissionEnum;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\DueAudits;
use App\Services\NightAudit\ReadinessAdvice;
use App\Services\NightAudit\ReadinessItem;
use Spatie\LaravelData\Data;

/**
 * Everything the Run Night Audit sheet shows: the date it would close, what to fix first, what to look at, the
 * progress of the audit of that date or of the last one that finished, and, while the date has not ended, which audit
 * last closed a date and when the automatic audit closes this one.
 */
class NightAuditSheetData extends Data
{
    /**
     * @param  string|null  $date  `Y-m-d`, the current business date. Null while the property has no business date.
     * @param  bool  $canViewPacket  Whether the user holds `reports.view`, which opens the packet PDF of a Completed audit.
     * @param  bool  $dateEnded  False while the system date has not passed the business date. No run is allowed then, forced or not.
     * @param  string  $summary  One plain sentence on whether the audit can run and what it will do by itself.
     * @param  list<ReadinessGroupData>  $blockers
     * @param  list<ReadinessGroupData>  $warnings
     * @param  LastAuditData|null  $lastAudit  The last Completed audit of the property. Null before the first one.
     * @param  NextAutoAuditData|null  $nextAutoAudit  When the automatic audit closes the current date. Null when it is off.
     */
    public function __construct(
        public ?string $date,
        public ?string $nextDate,
        public ?BusinessDateStatus $status,
        public array $blockers,
        public array $warnings,
        public ?NightAuditProgressData $audit,
        public bool $dateEnded,
        public string $summary,
        public bool $canForce,
        public bool $canViewPacket,
        public ?LastAuditData $lastAudit,
        public ?NextAutoAuditData $nextAutoAudit,
    ) {}

    public static function forProperty(Property $property, AuditReadiness $readiness, CurrentBusinessDate $currentBusinessDate, ReadinessAdvice $advice, DueAudits $dueAudits, User $user): self
    {
        $businessDate = $currentBusinessDate->get($property);
        $groups = self::groups($readiness->items($property), $property, $advice, $user->can(PermissionEnum::FrontDeskCheckIn->value), $user->can(PermissionEnum::FrontDeskCheckOut->value));
        $blockers = array_values(array_filter($groups, fn (ReadinessGroupData $group): bool => $group->blocker));
        $warnings = array_values(array_filter($groups, fn (ReadinessGroupData $group): bool => ! $group->blocker));
        $audit = $businessDate->nightAudit ?? NightAudit::query()->where('property_id', $property->id)->latest('id')->first();
        $lastAudit = NightAudit::query()->where('property_id', $property->id)->where('status', AuditStatus::Completed->value)
            ->with(['businessDate', 'runBy'])->latest('completed_at')->first();
        $nextAutoAudit = $businessDate === null ? null : $dueAudits->dueAt($property, $businessDate->date);

        return new self(
            date: $businessDate?->date->toDateString(),
            nextDate: $businessDate?->date->addDay()->toDateString(),
            status: $businessDate?->status,
            blockers: $blockers,
            warnings: $warnings,
            audit: $audit === null ? null : NightAuditProgressData::fromAudit($audit->loadMissing('businessDate')),
            dateEnded: $readiness->businessDateEnded($property),
            summary: self::summary(count($blockers), count($warnings)),
            canForce: $user->can(PermissionEnum::NightAuditForce->value),
            canViewPacket: $user->can(PermissionEnum::ReportsView->value),
            lastAudit: $lastAudit === null ? null : LastAuditData::fromAudit($lastAudit, $property->settings->time_zone ?? config('app.timezone')),
            nextAutoAudit: $nextAutoAudit === null ? null : NextAutoAuditData::fromMoment($nextAutoAudit),
        );
    }

    /**
     * One group for each check and reservation, in the order of the items (blockers first).
     *
     * @param  list<ReadinessItem>  $items
     * @return list<ReadinessGroupData>
     */
    private static function groups(array $items, Property $property, ReadinessAdvice $advice, bool $canCheckIn, bool $canCheckOut): array
    {
        $byKey = [];

        foreach ($items as $item) {
            $byKey[$item->check->value.':'.$item->reservationId][] = $item;
        }

        return array_values(array_map(fn (array $group): ReadinessGroupData => ReadinessGroupData::fromItems($group, $property, $advice, $canCheckIn, $canCheckOut), $byKey));
    }

    private static function summary(int $blockers, int $warnings): string
    {
        if ($blockers > 0) {
            return trans_choice('Fix :count thing before you run the audit.|Fix :count things before you run the audit.', $blockers);
        }

        if ($warnings > 0) {
            return trans_choice('Ready to run. :count reservation needs a look.|Ready to run. :count reservations need a look.', $warnings);
        }

        return __('Ready to run. Nothing needs you.');
    }
}
