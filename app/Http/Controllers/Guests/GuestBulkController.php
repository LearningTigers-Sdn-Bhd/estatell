<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\BulkUpdateGuests;
use App\Enums\GuestBulkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\BulkUpdateGuestsRequest;
use App\Models\Property;
use App\Services\Guests\GuestDirectory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GuestBulkController extends Controller
{
    public function __construct(private BulkUpdateGuests $bulkUpdateGuests, private GuestDirectory $guestDirectory) {}

    public function markVip(BulkUpdateGuestsRequest $request, Property $property): RedirectResponse
    {
        return $this->run($request, $property, GuestBulkAction::MarkVip);
    }

    public function unmarkVip(BulkUpdateGuestsRequest $request, Property $property): RedirectResponse
    {
        return $this->run($request, $property, GuestBulkAction::UnmarkVip);
    }

    public function blacklist(BulkUpdateGuestsRequest $request, Property $property): RedirectResponse
    {
        return $this->run($request, $property, GuestBulkAction::Blacklist);
    }

    public function unblacklist(BulkUpdateGuestsRequest $request, Property $property): RedirectResponse
    {
        return $this->run($request, $property, GuestBulkAction::Unblacklist);
    }

    public function anonymize(BulkUpdateGuestsRequest $request, Property $property): RedirectResponse
    {
        return $this->run($request, $property, GuestBulkAction::Anonymize);
    }

    private function run(BulkUpdateGuestsRequest $request, Property $property, GuestBulkAction $action): RedirectResponse
    {
        $guests = $this->guestDirectory->selected($property, $request->guestIds());
        $result = ($this->bulkUpdateGuests)($request->user(), $guests, $action, $request->validated('reason'));

        Inertia::flash('guestBulkResult', $result->toArray());
        Inertia::flash('toast', [
            'type' => $result->skippedIds === [] ? 'success' : ($result->changedIds === [] ? 'error' : 'info'),
            'message' => $action->summary(count($result->changedIds), count($result->unchangedIds), count($result->skippedIds)),
        ]);

        return back();
    }
}
