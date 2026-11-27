<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\CreateMarketSegment;
use App\Actions\Reservations\UpdateMarketSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\MarketSegmentRequest;
use App\Models\MarketSegment;
use App\Models\Property;
use App\Services\Reservations\MarketSegments;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Settings › Market segments. See docs/12-reservations/15-market-segments.md. */
class MarketSegmentController extends Controller
{
    public function index(Property $property, MarketSegments $segments): Response
    {
        return Inertia::render('settings/market-segments/index', [
            'segments' => $segments->all($property),
        ]);
    }

    public function store(MarketSegmentRequest $request, Property $property, CreateMarketSegment $createSegment): RedirectResponse
    {
        $segment = $createSegment($property, $request->user(), $request->name());

        Inertia::flash('toast', $segment === null
            ? ['type' => 'error', 'message' => __('The market segments changed. Reload the page and try again.')]
            : ['type' => 'success', 'message' => __(':name added.', ['name' => $segment->name])]);

        return to_route('settings.market-segments.index', $property);
    }

    public function update(MarketSegmentRequest $request, Property $property, MarketSegment $marketSegment, UpdateMarketSegment $updateSegment): RedirectResponse
    {
        $saved = $updateSegment($request->user(), $marketSegment, $request->name(), $request->boolean('active', $marketSegment->active));

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->name()])]
            : ['type' => 'error', 'message' => __('The market segments changed. Reload the page and try again.')]);

        return to_route('settings.market-segments.index', $property);
    }
}
