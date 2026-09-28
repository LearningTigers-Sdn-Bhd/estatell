<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\CompleteOwnerSetup;
use App\Data\Onboarding\OwnerSetupData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompleteOwnerSetupRequest;
use App\Models\User;
use App\Services\Staff\PropertyOwners;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class OwnerAccountSetupController extends Controller
{
    public function show(Request $request, User $user, PropertyOwners $propertyOwners): Response
    {
        $owner = $this->availableOwner($user, $propertyOwners);

        return Inertia::render('auth/owner-setup', [
            'owner' => $owner === null ? null : OwnerSetupData::fromModel($owner),
            'actionUrl' => $owner === null ? null : URL::temporarySignedRoute(
                'guest.owner-setup.store',
                CarbonImmutable::createFromTimestamp($request->integer('expires')),
                ['user' => $owner],
            ),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(
        CompleteOwnerSetupRequest $request,
        User $user,
        CompleteOwnerSetup $completeOwnerSetup,
        PropertyOwners $propertyOwners,
    ): RedirectResponse {
        $owner = $this->availableOwner($user, $propertyOwners);

        if ($owner === null || ! $completeOwnerSetup($owner, $request->string('password')->toString())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This setup link is no longer available.')]);

            return to_route('login');
        }

        Auth::login($owner->refresh());
        $request->session()->regenerate();

        return to_route('home.redirect');
    }

    private function availableOwner(User $user, PropertyOwners $propertyOwners): ?User
    {
        if ($user->hasVerifiedEmail() || $user->property === null) {
            return null;
        }

        $owner = $propertyOwners->firstFor($user->property);

        return $owner?->is($user) ? $user->loadMissing('property') : null;
    }
}
