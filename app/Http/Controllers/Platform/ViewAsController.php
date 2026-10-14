<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\StartImpersonation;
use App\Data\Navigation\NavItemData;
use App\Enums\ImpersonationReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StartImpersonationRequest;
use App\Models\Property;
use App\Models\User;
use App\Navigation\AdminNavigation;
use App\Navigation\PartnerNavigation;
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

        if ($impersonation->forProperty($property)) {
            $to = $request->query('to');

            return redirect($impersonation->destination($property, is_string($to) ? $to : null));
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
            'cancelUrl' => $this->cancelUrl($request, $property, $impersonation),
            'switchFrom' => $this->switchFrom($request, $impersonation),
            ...$this->supportNavigation($impersonation),
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
     * The open session's property, when the support user is about to switch to another one.
     *
     * @return array{name: string, code: string}|null
     */
    private function switchFrom(Request $request, Impersonation $impersonation): ?array
    {
        $current = $impersonation->isActive() ? $request->user()->property : null;

        return $current === null ? null : ['name' => $current->name, 'code' => $current->code];
    }

    /**
     * Cancel goes back to the page the support user came from. While a session is open that is its dashboard,
     * because the signed-in user cannot open the admin or partner pages.
     */
    private function cancelUrl(Request $request, Property $property, Impersonation $impersonation): string
    {
        if ($impersonation->isActive()) {
            return route('dashboard', ['property' => $request->user()->property->code], absolute: false);
        }

        return $request->user()->is_admin
            ? route('admin.properties.show', ['property' => $property->code], absolute: false)
            : route('partner.properties.show', ['code' => $property->code], absolute: false);
    }

    /**
     * While a session is open the shared sidebar items are empty (the signed-in user is staff), so the page brings
     * the support user's own. They replace the shared props on this page only and make the page choose the admin or
     * partner layout.
     *
     * @return array<string, list<NavItemData>>
     */
    private function supportNavigation(Impersonation $impersonation): array
    {
        $supportUser = $impersonation->supportUser();

        return match (true) {
            $supportUser === null => [],
            $supportUser->is_admin => ['adminNavigation' => app(AdminNavigation::class)->items()],
            default => ['partnerNavigation' => app(PartnerNavigation::class)->items()],
        };
    }
}
