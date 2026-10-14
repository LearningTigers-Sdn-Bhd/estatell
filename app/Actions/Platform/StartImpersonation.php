<?php

namespace App\Actions\Platform;

use App\Enums\ImpersonationReason;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;

class StartImpersonation
{
    public function __construct(private Impersonation $impersonation, private StopImpersonation $stopImpersonation) {}

    /**
     * Sign the support user in as one staff user of the property and return the path to open. Returns null, and
     * changes nothing, when the support user may not view the property, the owner switched support access off, or
     * the user is not active staff of that property. Two-factor is checked by the `support.2fa` route middleware.
     *
     * `$actor` is the signed-in user. During a session that is the staff user, so the support user behind it is kept.
     * An open session is ended first (logged, owner told), so switching property logs two events.
     */
    public function __invoke(User $actor, Property $property, User $staff, ImpersonationReason $reason, ?string $note, ?string $to): ?string
    {
        $supportUser = $this->impersonation->supportUser() ?? $actor;

        if (Gate::forUser($actor)->denies('viewAs', $property)) {
            return null;
        }

        if ($staff->property_id !== $property->id || $staff->deactivated_at !== null) {
            return null;
        }

        if (! ($property->settings?->allow_support_access ?? true)) {
            return null;
        }

        if ($this->impersonation->isActive()) {
            ($this->stopImpersonation)();
        }

        Session::put(Impersonation::SESSION_KEY, [
            'support_user_id' => $supportUser->id,
            'user_id' => $staff->id,
            'property_id' => $property->id,
            'reason' => $reason->value,
            'note' => $note,
            'started_at' => now()->toIso8601String(),
            'return_url' => $supportUser->is_admin
                ? route('admin.properties.show', ['property' => $property->code], absolute: false)
                : route('partner.properties.show', ['code' => $property->code], absolute: false),
        ]);

        Auth::login($staff);

        activity()
            ->performedOn($property)
            ->causedBy($supportUser)
            ->event('impersonation.started')
            ->withProperties($this->impersonation->logProperties())
            ->log('Support session started');

        return $this->impersonation->destination($property, $to);
    }
}
