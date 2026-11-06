<?php

namespace App\Http\Controllers\Property;

use App\Actions\Property\RemovePropertyPhoto;
use App\Actions\Property\ReorderPropertyPhotos;
use App\Actions\Property\UploadPropertyPhotos;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\ReorderPropertyPhotosRequest;
use App\Http\Requests\Property\UploadPropertyPhotosRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Saves the property album. `PropertySettingsController::photos` shows the tab. See docs/02-property/01-properties.md#photos.
 */
class PropertyPhotoController extends Controller
{
    public function store(UploadPropertyPhotosRequest $request, Property $property, UploadPropertyPhotos $uploadPhotos): RedirectResponse
    {
        Inertia::flash('toast', $uploadPhotos($request->user(), $property, $request->photos())
            ? ['type' => 'success', 'message' => __('Photos uploaded.')]
            : ['type' => 'error', 'message' => __('A property can have up to :count photos.', ['count' => Property::PHOTO_LIMIT])]);

        return back();
    }

    public function reorder(ReorderPropertyPhotosRequest $request, Property $property, ReorderPropertyPhotos $reorderPhotos): RedirectResponse
    {
        if (! $reorderPhotos($request->user(), $property, $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The photos changed. Reload the page and try again.')]);
        }

        return back();
    }

    public function destroy(Request $request, Property $property, int $photo, RemovePropertyPhoto $removePhoto): RedirectResponse
    {
        Inertia::flash('toast', $removePhoto($request->user(), $property, $photo)
            ? ['type' => 'success', 'message' => __('Photo removed.')]
            : ['type' => 'error', 'message' => __('The photo could not be removed. Reload the page and try again.')]);

        return back();
    }
}
