<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\StartImpersonation;
use App\Enums\ImpersonationReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StartImpersonationRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use App\Services\Platform\ViewAsStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ViewAsController extends Controller
{
    /**
     * The view-as page, pre-filled from `to` and `reason`. Someone who may not view the property gets a 404.
     */
    public function show(Request $request, Property $property, Impersonation $impersonation, ViewAsStaff $viewAsStaff): Response|RedirectResponse
    {
        Gate::authorize('viewAs', $property);

        if ($impersonation->isActive()) {
            return $this->whileImpersonating($request, $property, $impersonation);
        }

        $to = $request->query('to');
        $to = is_string($to) ? $to : null;
        $staff = $viewAsStaff->for($property);

        return Inertia::render('view-as/show', [
            'staff' => $staff,
            // Owner first, so the first member is the pre-selected one.
            'defaultUserId' => $staff[0]->id ?? null,
            'reasons' => ImpersonationReason::options(),
            'reason' => ImpersonationReason::fromLink($request->string('reason')->toString())->value,
            'to' => $to !== null && $impersonation->accepts($property, $to) ? $to : null,
            'opens' => $impersonation->destination($property, $to),
            'consent' => $property->settings?->allow_support_access ?? true,
            'cancelUrl' => $request->user()->is_admin
                ? route('admin.properties.show', ['property' => $property->code], absolute: false)
                : route('partner.properties.show', ['code' => $property->code], absolute: false),
        ]);
    }

    /**
     * Start the session as the chosen staff user and open the page.
     */
    public function store(StartImpersonationRequest $request, Property $property, StartImpersonation $startImpersonation): RedirectResponse
    {
        $path = $startImpersonation(
            $request->user(),
            $property,
            User::query()->findOrFail($request->integer('user')),
            ImpersonationReason::from($request->string('reason')->toString()),
            $request->filled('note') ? $request->string('note')->trim()->toString() : null,
            $request->string('to')->toString() ?: null,
        );

        if ($path === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Owner has disabled support access.')]);

            return back();
        }

        return redirect($path);
    }

    /**
     * A session is open. The same property opens directly. Another property needs the session ended first.
     */
    private function whileImpersonating(Request $request, Property $property, Impersonation $impersonation): RedirectResponse
    {
        if ($impersonation->forProperty($property)) {
            $to = $request->query('to');

            return redirect($impersonation->destination($property, is_string($to) ? $to : null));
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('End your support session before viewing another property.')]);

        return redirect()->route('dashboard', ['property' => $request->user()->property->code]);
    }
}
