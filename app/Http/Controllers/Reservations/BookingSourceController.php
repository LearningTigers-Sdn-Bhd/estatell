<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\CreateBookingSource;
use App\Actions\Reservations\DeleteBookingSource;
use App\Actions\Reservations\ReorderBookingSources;
use App\Actions\Reservations\UpdateBookingSource;
use App\Data\Reservations\BookingSourceChannelOptionData;
use App\Data\Reservations\BookingSourceRowData;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\BookingSourceRequest;
use App\Http\Requests\Reservations\ReorderBookingSourcesRequest;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Services\Reservations\BookingSourceUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingSourceController extends Controller
{
    /**
     * Load the property's bounded list of booking sources once for browser-side search and ordering.
     */
    public function index(Property $property, BookingSourceUsage $usage): Response
    {
        $counts = $usage->counts($property);

        return Inertia::render('settings/booking-sources/index', [
            'sources' => $property->bookingSources()
                ->with('otaChannel')
                ->ordered()
                ->get()
                ->map(fn (BookingSource $source): BookingSourceRowData => BookingSourceRowData::fromModel($source, $counts[$source->id] ?? 0))
                ->all(),
            'types' => BookingSourceType::options(),
            'channels' => OtaChannel::query()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (OtaChannel $channel): BookingSourceChannelOptionData => BookingSourceChannelOptionData::fromModel($channel))
                ->all(),
            'icons' => BookingSourceIcon::options(),
            'colors' => SwatchColor::options(),
        ]);
    }

    public function store(BookingSourceRequest $request, Property $property, CreateBookingSource $createBookingSource): RedirectResponse
    {
        $source = $createBookingSource($property, $request->user(), $request->validated());

        Inertia::flash('toast', $source === null
            ? ['type' => 'error', 'message' => __('The booking sources changed. Reload the page and try again.')]
            : ['type' => 'success', 'message' => __(':name created.', ['name' => $source->name])]);

        return to_route('settings.booking-sources.index', $property);
    }

    public function update(BookingSourceRequest $request, Property $property, BookingSource $bookingSource, UpdateBookingSource $updateBookingSource): RedirectResponse
    {
        Inertia::flash('toast', $updateBookingSource($request->user(), $bookingSource, $request->validated())
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->validated('name', $bookingSource->name)])]
            : ['type' => 'error', 'message' => __(':name changed. Reload the page and try again.', ['name' => $bookingSource->name])]);

        return to_route('settings.booking-sources.index', $property);
    }

    public function reorder(ReorderBookingSourcesRequest $request, Property $property, ReorderBookingSources $reorderBookingSources): RedirectResponse
    {
        if (! $reorderBookingSources($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The booking sources changed. Reload the page and try again.')]);
        }

        return to_route('settings.booking-sources.index', $property);
    }

    public function destroy(Request $request, Property $property, BookingSource $bookingSource, DeleteBookingSource $deleteBookingSource): RedirectResponse
    {
        Inertia::flash('toast', $deleteBookingSource($request->user(), $bookingSource)
            ? ['type' => 'success', 'message' => __(':name deleted.', ['name' => $bookingSource->name])]
            : ['type' => 'error', 'message' => __(':name cannot be deleted. Turn it off with Available instead.', ['name' => $bookingSource->name])]);

        return to_route('settings.booking-sources.index', $property);
    }
}
