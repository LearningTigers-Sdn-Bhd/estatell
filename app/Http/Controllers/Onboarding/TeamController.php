<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingTeam;
use App\Data\Onboarding\OnboardingRoleData;
use App\Data\Onboarding\OnboardingStaffData;
use App\Enums\DefaultRoleEnum;
use App\Enums\OnboardingStep;
use App\Enums\SpreadsheetFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveTeamRequest;
use App\Http\Requests\Staff\ImportStaffRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\TeamRoles;
use App\Services\Staff\StaffImportReader;
use App\Services\Staff\StaffImportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TeamController extends Controller
{
    /**
     * The signed-in owner, the saved staff and the roles a new row can take. A locked step opens as a preview.
     */
    public function edit(Request $request, Property $property, OnboardingProgress $progress, TeamRoles $teamRoles): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Team);
        $owner = $request->user();
        $roles = $teamRoles->for($property);

        return Inertia::render('onboarding/team', [
            'owner' => ['name' => $owner->name, 'email' => $owner->email],
            'staff' => $property->users()
                ->whereKeyNot($owner->getKey())
                ->whereNull('deactivated_at')
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): OnboardingStaffData => OnboardingStaffData::fromModel($user)),
            'roles' => $roles->map(fn (Role $role): OnboardingRoleData => OnboardingRoleData::fromModel($role)),
            'defaultRoleId' => (string) $roles->firstWhere('default_role', DefaultRoleEnum::FrontDesk)?->id,
            'maxRows' => StaffImportReader::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveTeamRequest $request, Property $property, SaveOnboardingTeam $saveTeam): RedirectResponse
    {
        $added = $saveTeam($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => $added > 0
            ? trans_choice(':count person added.|:count people added.', $added)
            : __('Team saved.')]);

        $next = OnboardingStep::Team->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    /**
     * Read an uploaded CSV or XLSX file into rows. Nothing is saved: the owner reviews the rows, then saves the table.
     */
    public function import(ImportStaffRequest $request, Property $property, TeamRoles $teamRoles, StaffImportReader $reader): JsonResponse
    {
        return response()->json(['rows' => $reader->read($request->file('file'), $teamRoles->for($property))]);
    }

    /**
     * The empty file with the name, email and role columns.
     */
    public function template(Property $property, SpreadsheetFormat $format, StaffImportTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->write($format), 'team.'.$format->value)
            ->deleteFileAfterSend();
    }
}
