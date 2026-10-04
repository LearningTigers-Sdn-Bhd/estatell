<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\CreateStaffWithTemporaryPassword;
use App\Actions\Staff\ExportTemporaryPasswords;
use App\Actions\Staff\ResetTemporaryPassword;
use App\Data\Staff\TemporaryPasswordMessageData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AddStaffRequest;
use App\Http\Requests\Staff\TemporaryPasswordUsersRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StaffTemporaryPasswordController extends Controller
{
    /**
     * Add one staff user with a temp password. The page then opens the copy message for them.
     */
    public function store(AddStaffRequest $request, Property $property, CreateStaffWithTemporaryPassword $createStaff): RedirectResponse
    {
        $user = $createStaff(
            $property,
            $request->user(),
            $request->validated('name'),
            $request->validated('email'),
            Role::query()->findOrFail($request->validated('role_id')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added.', ['name' => $user->name])]);
        Inertia::flash('staffAddedId', $user->id);

        return to_route('settings.staff.index', $property);
    }

    /**
     * Give the chosen staff new temp passwords. Yourself, Owners and deactivated users are skipped.
     */
    public function reset(TemporaryPasswordUsersRequest $request, Property $property, ResetTemporaryPassword $resetTemporaryPassword): RedirectResponse
    {
        $actor = $request->user();
        $reset = $this->chosenUsers($request, $property)
            ->filter(fn (User $user): bool => $resetTemporaryPassword($actor, $user))
            ->count();
        $skipped = count($request->validated('user_ids')) - $reset;

        Inertia::flash('toast', match (true) {
            $reset === 0 => ['type' => 'error', 'message' => __('No temp password was reset. Owners, deactivated staff and your own account are skipped.')],
            $skipped > 0 => ['type' => 'success', 'message' => __('New temp password for :count staff. :skipped skipped.', ['count' => $reset, 'skipped' => $skipped])],
            default => ['type' => 'success', 'message' => __('New temp password for :count staff.', ['count' => $reset])],
        });

        return back(fallback: route('settings.staff.index', $property));
    }

    /**
     * The copy message for the chosen staff who have an unexpired temp password.
     */
    public function message(TemporaryPasswordUsersRequest $request, Property $property, ExportTemporaryPasswords $exportTemporaryPasswords, TemporaryPasswordMessage $message): JsonResponse
    {
        $slips = $exportTemporaryPasswords($property, $request->user(), $this->chosenUsers($request, $property), TemporaryPasswordExportFormat::Message);

        return response()->json(new TemporaryPasswordMessageData(
            text: $message->for($slips),
            count: $slips->count(),
            skipped: count($request->validated('user_ids')) - $slips->count(),
        ));
    }

    /**
     * @return Collection<int, User>
     */
    private function chosenUsers(TemporaryPasswordUsersRequest $request, Property $property): Collection
    {
        return $property->users()->whereKey($request->validated('user_ids'))->with('roles')->orderBy('name')->get();
    }
}
