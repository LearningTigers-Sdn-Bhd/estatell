<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Boats\ResetDepartureDate;
use App\Actions\Boats\SailingResult;
use App\Actions\Boats\SaveSailing;
use App\Data\Boats\SailingData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\SaveBoatManifestSailingsRequest;
use App\Models\BoatDeparture;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The Boats and crew panel on the Boat manifest: saves the main sailing of each departure on the manifest date through
 * `SaveSailing`, or goes back to the timetable through `ResetDepartureDate`. The panel goes when the boats board comes.
 * See docs/24-boats/03-boat-manifest.md#boats-and-crew.
 */
class BoatManifestSailingsController extends Controller
{
    public function update(SaveBoatManifestSailingsRequest $request, Property $property, SaveSailing $saveSailing, ResetDepartureDate $resetDate): RedirectResponse
    {
        $validated = $request->validated();
        $date = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay();

        DB::transaction(function () use ($request, $property, $validated, $date, $saveSailing, $resetDate): void {
            foreach ($validated['sailings'] as $index => $item) {
                $departure = BoatDeparture::query()->where('property_id', $property->id)->findOrFail((int) $item['boat_departure_id']);
                $result = filter_var($item['reset_to_default'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? $resetDate($request->user(), $departure, $date)
                    : $saveSailing($request->user(), $departure, $date, new SailingData(
                        boatId: isset($item['boat_id']) ? (int) $item['boat_id'] : null,
                        inChargeId: isset($item['in_charge_id']) ? (int) $item['in_charge_id'] : null,
                        assistantIds: array_map(intval(...), $item['assistant_ids'] ?? []),
                    ));

                if ($result->isRefused()) {
                    throw ValidationException::withMessages(["sailings.{$index}.".$this->field($result) => $this->message($result)]);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boats and crew saved.')]);

        return back();
    }

    private function field(SailingResult $result): string
    {
        return match ($result->refusal) {
            SailingResult::REFUSAL_IN_CHARGE_REQUIRED => 'in_charge_id',
            default => 'boat_id',
        };
    }

    private function message(SailingResult $result): string
    {
        return match ($result->refusal) {
            SailingResult::REFUSAL_BOAT_UNAVAILABLE => __('Pick an available boat and crew.'),
            SailingResult::REFUSAL_IN_CHARGE_REQUIRED => __('Pick who is in charge first.'),
            SailingResult::REFUSAL_SAILING_HAS_GUESTS => trans_choice('Move the :count guest first.|Move the :count guests first.', $result->guests),
            SailingResult::REFUSAL_DATE_CLOSED => __('This date is closed. Past dates are read only.'),
            default => __('The boats could not be saved.'),
        };
    }
}
