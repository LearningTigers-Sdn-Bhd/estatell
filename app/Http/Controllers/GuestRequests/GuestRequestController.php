<?php

namespace App\Http\Controllers\GuestRequests;

use App\Actions\GuestRequests\AssignGuestRequest;
use App\Actions\GuestRequests\CancelGuestRequest;
use App\Actions\GuestRequests\GuestRequestResult;
use App\Actions\GuestRequests\OpenGuestRequest;
use App\Actions\GuestRequests\ReopenGuestRequest;
use App\Actions\GuestRequests\ResolveGuestRequest;
use App\Actions\GuestRequests\StartGuestRequest;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuestRequests\AssignGuestRequestRequest;
use App\Http\Requests\GuestRequests\CancelGuestRequestRequest;
use App\Http\Requests\GuestRequests\ResolveGuestRequestRequest;
use App\Http\Requests\GuestRequests\StoreGuestRequestRequest;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\GuestRequests\GuestRequestListBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestRequestController extends Controller
{
    /**
     * The work queue: Open and InProgress requests, oldest first, unless a filter says otherwise.
     */
    public function index(Request $request, Property $property, GuestRequestListBuilder $builder): Response
    {
        $status = $request->string('status', 'active')->toString();
        $type = GuestRequestType::tryFrom($request->string('type')->toString());
        $user = $request->user();

        return Inertia::render('guest-requests/index', [
            'requests' => $builder->rows($property, $user, $status, $type),
            'filters' => ['status' => $status, 'type' => $type === null ? '' : $type->value],
            'statuses' => [['value' => 'active', 'label' => __('Open and in progress')], ['value' => 'all', 'label' => __('All')], ...array_map(fn (GuestRequestStatus $case): array => ['value' => $case->value, 'label' => $case->label()], GuestRequestStatus::cases())],
            'types' => GuestRequestType::options(),
            'sources' => GuestRequestSource::staffOptions(),
            'assignees' => $builder->assignees($property),
            'stays' => $builder->stayOptions($property),
            'canManage' => $user->can(PermissionEnum::GuestRequestsManage->value),
        ]);
    }

    public function store(StoreGuestRequestRequest $request, Property $property, OpenGuestRequest $openRequest): RedirectResponse
    {
        $stay = $request->filled('stay_id') ? ReservationUnit::query()->where('property_id', $property->id)->find($request->integer('stay_id')) : null;

        if ($request->filled('stay_id') && $stay === null) {
            return back()->withErrors(['stay_id' => __('Pick a stay of this property.')]);
        }

        $result = $openRequest(
            $request->user(),
            $property,
            GuestRequestType::from($request->string('type')->toString()),
            GuestRequestSource::from($request->string('source')->toString()),
            $request->string('details')->toString(),
            $stay,
            $request->filled('room_id') ? $request->integer('room_id') : null,
        );

        if ($result->isRefused()) {
            return back()->withErrors([$stay === null ? 'room_id' : 'stay_id' => $result->refusal->label()]);
        }

        return $this->answered($property, $result, __('Request recorded.'));
    }

    public function assign(AssignGuestRequestRequest $request, Property $property, GuestRequest $guestRequest, AssignGuestRequest $assignRequest): RedirectResponse
    {
        return $this->answered($property, $assignRequest($request->user(), $guestRequest, $request->assignee()), __('Request assigned.'));
    }

    public function start(Request $request, Property $property, GuestRequest $guestRequest, StartGuestRequest $startRequest): RedirectResponse
    {
        return $this->answered($property, $startRequest($request->user(), $guestRequest), __('Request started.'));
    }

    public function resolve(ResolveGuestRequestRequest $request, Property $property, GuestRequest $guestRequest, ResolveGuestRequest $resolveRequest): RedirectResponse
    {
        return $this->answered($property, $resolveRequest($request->user(), $guestRequest, $request->input('note')), __('Request resolved.'));
    }

    public function cancel(CancelGuestRequestRequest $request, Property $property, GuestRequest $guestRequest, CancelGuestRequest $cancelRequest): RedirectResponse
    {
        return $this->answered($property, $cancelRequest($request->user(), $guestRequest, $request->string('note')->toString()), __('Request cancelled.'));
    }

    public function reopen(Request $request, Property $property, GuestRequest $guestRequest, ReopenGuestRequest $reopenRequest): RedirectResponse
    {
        return $this->answered($property, $reopenRequest($request->user(), $guestRequest), __('Request reopened.'));
    }

    /**
     * A refusal is an error toast and nothing changes; a success is a toast. Both go back to the list.
     */
    private function answered(Property $property, GuestRequestResult $result, string $success): RedirectResponse
    {
        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $result->refusal->label()]
            : ['type' => 'success', 'message' => $success]);

        return to_route('guest-requests.index', $property);
    }
}
