<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\CreateAmenity;
use App\Actions\BookingEngine\ReorderAmenities;
use App\Actions\BookingEngine\SetAmenityActive;
use App\Actions\BookingEngine\UpdateAmenity;
use App\Data\BookingEngine\AmenityCategoryData;
use App\Data\BookingEngine\AmenityData;
use App\Enums\AmenityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AmenityRequest;
use App\Http\Requests\Admin\ReorderAmenitiesRequest;
use App\Http\Requests\Admin\SetAmenityActiveRequest;
use App\Models\Amenity;
use App\Models\AmenityCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shared amenity list. See docs/28-booking-engine/06-content-registries.md#amenities.
 */
class AmenityController extends Controller
{
    /**
     * List every amenity and category, retired or not. The list is bounded (about 500), so it loads once and the browser filters it.
     * The open tab and the List view's page live in the URL (`?tab=room` or `categories`, default `property`, and `?page=2`)
     * so they survive a refresh. The browser cuts the page from the loaded list.
     */
    public function index(Request $request): Response
    {
        $tab = $request->string('tab')->toString();
        $tab = $tab === 'categories' ? $tab : (AmenityType::tryFrom($tab) ?? AmenityType::Property)->value;

        return Inertia::render('admin/amenities/index', [
            'amenities' => Amenity::query()->with('category')->ordered()->get()
                ->map(fn (Amenity $amenity): AmenityData => AmenityData::fromModel($amenity))
                ->values(),
            'categories' => AmenityCategory::query()->withCount('amenities')->ordered()->get()
                ->map(fn (AmenityCategory $category): AmenityCategoryData => AmenityCategoryData::fromModel($category))
                ->values(),
            'typeOptions' => AmenityType::options(),
            'tab' => $tab,
            'page' => max(1, $request->integer('page', 1)),
            'listUrl' => route('admin.amenities.index', ['tab' => $tab]),
        ]);
    }

    public function store(AmenityRequest $request, CreateAmenity $createAmenity): RedirectResponse
    {
        $amenity = $createAmenity(
            $request->user(),
            $request->string('name')->toString(),
            $request->category(),
        );

        Inertia::flash('toast', $amenity === null
            ? ['type' => 'error', 'message' => __('An amenity with this name already exists for this type.')]
            : ['type' => 'success', 'message' => __(':name added.', ['name' => $amenity->name])]);

        return back();
    }

    public function update(AmenityRequest $request, Amenity $amenity, UpdateAmenity $updateAmenity): RedirectResponse
    {
        Inertia::flash('toast', $updateAmenity($request->user(), $amenity, $request->string('name')->toString(), $request->category())
            ? ['type' => 'success', 'message' => __('Amenity saved.')]
            : ['type' => 'error', 'message' => __('An amenity with this name already exists for this type.')]);

        return back();
    }

    public function active(SetAmenityActiveRequest $request, Amenity $amenity, SetAmenityActive $setAmenityActive): RedirectResponse
    {
        Inertia::flash('toast', $setAmenityActive($request->user(), $amenity, $request->boolean('active'))
            ? ['type' => 'success', 'message' => __('Amenity saved.')]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return back();
    }

    public function reorder(ReorderAmenitiesRequest $request, ReorderAmenities $reorderAmenities): RedirectResponse
    {
        if (! $reorderAmenities($request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The amenities changed. Reload the page and try again.')]);
        }

        return back();
    }
}
