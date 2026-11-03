<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\CreateAmenity;
use App\Actions\BookingEngine\ReorderAmenities;
use App\Actions\BookingEngine\SetAmenityActive;
use App\Actions\BookingEngine\UpdateAmenity;
use App\Data\BookingEngine\AmenityData;
use App\Enums\AmenityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AmenityRequest;
use App\Http\Requests\Admin\ReorderAmenitiesRequest;
use App\Http\Requests\Admin\SetAmenityActiveRequest;
use App\Models\Amenity;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shared amenity list. See docs/28-booking-engine/06-content-registries.md#amenities.
 */
class AmenityController extends Controller
{
    /**
     * List every amenity, retired or not. The list is bounded (about 500), so it loads once and the browser filters it.
     */
    public function index(): Response
    {
        return Inertia::render('admin/amenities/index', [
            'amenities' => Amenity::query()->ordered()->get()
                ->map(fn (Amenity $amenity): AmenityData => AmenityData::fromModel($amenity))
                ->values(),
            'typeOptions' => AmenityType::options(),
        ]);
    }

    public function store(AmenityRequest $request, CreateAmenity $createAmenity): RedirectResponse
    {
        $amenity = $createAmenity(
            $request->user(),
            $request->string('name')->toString(),
            AmenityType::from($request->string('type')->toString()),
            $request->string('category')->toString(),
        );

        Inertia::flash('toast', $amenity === null
            ? ['type' => 'error', 'message' => __('An amenity with this name already exists for this type.')]
            : ['type' => 'success', 'message' => __(':name added.', ['name' => $amenity->name])]);

        return to_route('admin.amenities.index');
    }

    public function update(AmenityRequest $request, Amenity $amenity, UpdateAmenity $updateAmenity): RedirectResponse
    {
        Inertia::flash('toast', $updateAmenity($request->user(), $amenity, $request->string('name')->toString(), $request->string('category')->toString())
            ? ['type' => 'success', 'message' => __('Amenity saved.')]
            : ['type' => 'error', 'message' => __('An amenity with this name already exists for this type.')]);

        return to_route('admin.amenities.index');
    }

    public function active(SetAmenityActiveRequest $request, Amenity $amenity, SetAmenityActive $setAmenityActive): RedirectResponse
    {
        Inertia::flash('toast', $setAmenityActive($request->user(), $amenity, $request->boolean('active'))
            ? ['type' => 'success', 'message' => __('Amenity saved.')]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return to_route('admin.amenities.index');
    }

    public function reorder(ReorderAmenitiesRequest $request, ReorderAmenities $reorderAmenities): RedirectResponse
    {
        if (! $reorderAmenities($request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The amenities changed. Reload the page and try again.')]);
        }

        return to_route('admin.amenities.index');
    }
}
