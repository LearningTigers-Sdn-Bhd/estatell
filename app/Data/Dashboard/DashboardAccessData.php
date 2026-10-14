<?php

namespace App\Data\Dashboard;

use App\Enums\PermissionEnum;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * What the user may do from the dashboard: the quick actions and which tiles are sent.
 */
class DashboardAccessData extends Data
{
    public function __construct(
        public bool $canManageReservations,
        public bool $canViewReservations,
        public bool $canRunNightAudit,
        public bool $canViewReports,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            canManageReservations: $user->can(PermissionEnum::ReservationsManage->value),
            canViewReservations: $user->can(PermissionEnum::ReservationsView->value),
            canRunNightAudit: $user->can(PermissionEnum::NightAuditRun->value),
            canViewReports: $user->can(PermissionEnum::ReportsView->value),
        );
    }

    /**
     * Whether any tile is sent.
     */
    public function hasTiles(): bool
    {
        return $this->canViewReservations || $this->canViewReports;
    }
}
