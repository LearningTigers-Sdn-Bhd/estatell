<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\DeleteBoatJob;
use App\Actions\Boats\SailingResult;
use App\Actions\Boats\SaveBoatJob;
use App\Actions\Boats\SetBoatJobDone;
use App\Enums\SailingKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\SaveBoatJobRequest;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Services\Boats\SailingRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The boat jobs of the Boat Trips board: add, edit, delete and the Done tick. A job of another property, or a sailing that
 * is not a job, is a 404. A refusal is an error toast. See docs/24-boats/05-boats-board.md.
 */
class BoatJobController extends Controller
{
    public function store(SaveBoatJobRequest $request, Property $property, SaveBoatJob $saveJob, SailingRefusals $refusals): RedirectResponse
    {
        return $this->respond(
            $saveJob($request->user(), $property, $request->jobDate(), $request->jobType(), (string) $request->validated('leaves_at'), $request->validated('note'), $request->crew()),
            __('Boat job added.'),
            $refusals,
        );
    }

    public function update(SaveBoatJobRequest $request, Property $property, BoatSailing $sailing, SaveBoatJob $saveJob, SailingRefusals $refusals): RedirectResponse
    {
        $this->ensureJob($property, $sailing);

        return $this->respond(
            $saveJob($request->user(), $property, $request->jobDate(), $request->jobType(), (string) $request->validated('leaves_at'), $request->validated('note'), $request->crew(), $sailing),
            __('Boat job saved.'),
            $refusals,
        );
    }

    public function destroy(Request $request, Property $property, BoatSailing $sailing, DeleteBoatJob $deleteJob, SailingRefusals $refusals): RedirectResponse
    {
        $this->ensureJob($property, $sailing);

        return $this->respond($deleteJob($request->user(), $sailing), __('Boat job deleted.'), $refusals);
    }

    public function done(Request $request, Property $property, BoatSailing $sailing, SetBoatJobDone $setDone): RedirectResponse
    {
        $this->ensureJob($property, $sailing);

        $validated = $request->validate(['done' => ['required', 'boolean']]);

        $setDone($request->user(), $sailing, (bool) $validated['done']);

        return back();
    }

    private function ensureJob(Property $property, BoatSailing $job): void
    {
        abort_unless($job->property_id === $property->id && $job->kind === SailingKind::Job, 404);
    }

    private function respond(SailingResult $result, string $success, SailingRefusals $refusals): RedirectResponse
    {
        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message($result)]
            : ['type' => 'success', 'message' => $success]);

        return back();
    }
}
