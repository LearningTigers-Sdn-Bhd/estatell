<?php

namespace App\Http\Controllers\Staff;

use App\Data\Staff\RoleOptionData;
use App\Data\Staff\StaffInvitationData;
use App\Data\Staff\StaffMemberData;
use App\Enums\PermissionEnum;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\Staff\AssignableRoles;
use App\Services\Staff\StaffProperties;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private StaffProperties $staffProperties) {}

    /**
     * Staff page, Active tab.
     */
    public function index(Request $request, Property $property, AssignableRoles $assignableRoles): Response
    {
        $viewer = $request->user();

        return $this->renderTab($request, $property, $assignableRoles, 'active', [
            'staff' => $property->users()->wherePivotNull('deactivated_at')->with('roles', 'property')->orderBy('name')->get()
                ->map(fn (User $user): StaffMemberData => StaffMemberData::fromModel($user, $viewer, $property->id)),
        ]);
    }

    /**
     * Staff page, Deactivated tab.
     */
    public function deactivated(Request $request, Property $property, AssignableRoles $assignableRoles): Response
    {
        $viewer = $request->user();

        return $this->renderTab($request, $property, $assignableRoles, 'deactivated', [
            'staff' => $property->users()->wherePivotNotNull('deactivated_at')->with('roles', 'property')->orderByPivot('deactivated_at', 'desc')->get()
                ->map(fn (User $user): StaffMemberData => StaffMemberData::fromModel($user, $viewer, $property->id)),
        ]);
    }

    /**
     * Staff page, Invited tab.
     */
    public function invited(Request $request, Property $property, AssignableRoles $assignableRoles): Response
    {
        return $this->renderTab($request, $property, $assignableRoles, 'invited', [
            'invitations' => $property->staffInvitations()->open()->with('role', 'inviter')->latest('last_sent_at')->get()
                ->map(fn (StaffInvitation $invitation): StaffInvitationData => StaffInvitationData::fromModel($invitation)),
        ]);
    }

    /**
     * Each tab has its own URL. Counts, the invite form's roles and the group colleagues that can be added come on
     * every tab; rows only for the open tab.
     *
     * @param  'active'|'invited'|'deactivated'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Request $request, Property $property, AssignableRoles $assignableRoles, string $tab, array $tabProps): Response
    {
        $canManage = $request->user()->can(PermissionEnum::StaffManage->value);

        return Inertia::render('settings/staff/index', [
            'tab' => $tab,
            'counts' => [
                'active' => $property->users()->wherePivotNull('deactivated_at')->count(),
                'invited' => $property->staffInvitations()->open()->count(),
                'deactivated' => $property->users()->wherePivotNotNull('deactivated_at')->count(),
            ],
            'canManage' => $canManage,
            'exportFormats' => $canManage ? TemporaryPasswordExportFormat::options() : [],
            'roles' => $canManage
                ? $assignableRoles->for($property, $request->user())->map(fn ($role): RoleOptionData => RoleOptionData::fromModel($role))
                : [],
            'colleagues' => $canManage ? $this->staffProperties->colleaguesFor($property) : [],
            ...$tabProps,
        ]);
    }
}
