<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\CreateAmenityCategory;
use App\Actions\BookingEngine\DeleteAmenityCategory;
use App\Actions\BookingEngine\ReorderAmenityCategories;
use App\Actions\BookingEngine\UpdateAmenityCategory;
use App\Enums\AmenityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AmenityCategoryRequest;
use App\Http\Requests\Admin\ReorderAmenityCategoriesRequest;
use App\Models\AmenityCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Categories tab of the shared amenity list. See docs/28-booking-engine/06-content-registries.md#amenities.
 */
class AmenityCategoryController extends Controller
{
    public function store(AmenityCategoryRequest $request, CreateAmenityCategory $createCategory): RedirectResponse
    {
        $category = $createCategory(
            $request->user(),
            $request->string('name')->toString(),
            AmenityType::from($request->string('type')->toString()),
        );

        Inertia::flash('toast', $category === null
            ? ['type' => 'error', 'message' => __('A category with this name already exists for this type.')]
            : ['type' => 'success', 'message' => __(':name added.', ['name' => $category->name])]);

        return back();
    }

    public function update(AmenityCategoryRequest $request, AmenityCategory $amenityCategory, UpdateAmenityCategory $updateCategory): RedirectResponse
    {
        Inertia::flash('toast', $updateCategory($request->user(), $amenityCategory, $request->string('name')->toString())
            ? ['type' => 'success', 'message' => __('Category saved.')]
            : ['type' => 'error', 'message' => __('A category with this name already exists for this type.')]);

        return back();
    }

    public function destroy(Request $request, AmenityCategory $amenityCategory, DeleteAmenityCategory $deleteCategory): RedirectResponse
    {
        Inertia::flash('toast', $deleteCategory($request->user(), $amenityCategory)
            ? ['type' => 'success', 'message' => __(':name deleted.', ['name' => $amenityCategory->name])]
            : ['type' => 'error', 'message' => __(':name still has amenities. Move them first.', ['name' => $amenityCategory->name])]);

        return back();
    }

    public function reorder(ReorderAmenityCategoriesRequest $request, ReorderAmenityCategories $reorderCategories): RedirectResponse
    {
        if (! $reorderCategories($request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The categories changed. Reload the page and try again.')]);
        }

        return back();
    }
}
