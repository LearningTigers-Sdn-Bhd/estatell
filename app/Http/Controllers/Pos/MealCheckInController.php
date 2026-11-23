<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\RedeemMeal;
use App\Actions\Pos\UndoMealRedemption;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\RedeemMealRequest;
use App\Models\MealPeriod;
use App\Models\MealRedemption;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Pos\DailyMealCheckInList;
use App\Services\Pos\PosRefusalMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MealCheckInController extends Controller
{
    /**
     * The business date and the meal period (the one running now by the property's local time, else the next one, else
     * the first). The list loads once; the screen filters it with no request.
     */
    public function index(Request $request, Property $property, CurrentBusinessDate $currentBusinessDate, DailyMealCheckInList $list): Response
    {
        $date = $currentBusinessDate->date($property);
        $periods = MealPeriod::query()->where('property_id', $property->id)->active()->ordered()->get();
        $period = $periods->firstWhere('id', (int) $request->query('period')) ?? $this->currentPeriod($property, $periods);

        return Inertia::render('restaurant/meal-check-in', [
            'date' => $date?->toDateString(),
            'periods' => $periods->map(fn (MealPeriod $period): array => ['value' => (string) $period->id, 'label' => $period->name])->values(),
            'periodId' => $period?->id,
            'list' => $date === null || $period === null ? null : $list->forPeriod($property, $date, $period),
        ]);
    }

    public function store(RedeemMealRequest $request, Property $property, RedeemMeal $redeemMeal): RedirectResponse
    {
        $unit = ReservationUnit::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('reservation_unit_id'));
        $period = MealPeriod::query()->where('property_id', $property->id)->findOrFail((int) $request->validated('meal_period_id'));
        $result = $redeemMeal($request->user(), $unit, $period, CarbonImmutable::parse((string) $request->validated('service_date')), (int) $request->validated('guests'), $request->validated('notes'));

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Checked in.')]);

        return to_route('restaurant.meal-check-in.index', ['property' => $property, 'period' => $period->id]);
    }

    public function destroy(Request $request, Property $property, int $redemption, UndoMealRedemption $undoMealRedemption): RedirectResponse
    {
        $model = MealRedemption::query()->where('property_id', $property->id)->findOrFail($redemption);
        $result = $undoMealRedemption($request->user(), $model);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Check-in undone.')]);

        return to_route('restaurant.meal-check-in.index', ['property' => $property, 'period' => $model->meal_period_id]);
    }

    /**
     * @param  Collection<int, MealPeriod>  $periods
     */
    private function currentPeriod(Property $property, $periods): ?MealPeriod
    {
        $now = now($property->settings->time_zone ?? config('app.timezone'))->format('H:i:s');

        return $periods->first(fn (MealPeriod $period): bool => $period->starts_at <= $now && $now < $period->ends_at)
            ?? $periods->first(fn (MealPeriod $period): bool => $period->starts_at > $now)
            ?? $periods->first();
    }
}
