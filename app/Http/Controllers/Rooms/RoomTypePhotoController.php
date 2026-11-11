<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\RemoveRoomTypePhoto;
use App\Actions\Rooms\ReorderRoomTypePhotos;
use App\Actions\Rooms\UploadRoomTypePhotos;
use App\Data\Rooms\RoomTypeData;
use App\Data\Rooms\RoomTypePhotoData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\ReorderRoomTypePhotosRequest;
use App\Http\Requests\Rooms\UploadRoomTypePhotosRequest;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The Photos tab of the room type page. See docs/29-booking-engine/06-content-registries.md#room-photos.
 */
class RoomTypePhotoController extends Controller
{
    public function edit(Request $request, Property $property, RoomType $roomType): Response
    {
        return Inertia::render('settings/room-types/photos', [
            'roomType' => RoomTypeData::fromModel($roomType),
            'roomCount' => $roomType->rooms()->active()->count(),
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
            'photos' => $roomType->getMedia(RoomType::PHOTOS)
                ->map(fn (Media $media): RoomTypePhotoData => RoomTypePhotoData::fromModel($media))
                ->values(),
            'photoLimit' => RoomType::PHOTO_LIMIT,
            'photoMaxKb' => RoomType::PHOTO_MAX_KB,
        ]);
    }

    public function store(UploadRoomTypePhotosRequest $request, Property $property, RoomType $roomType, UploadRoomTypePhotos $uploadPhotos): RedirectResponse
    {
        $refusal = $uploadPhotos($request->user(), $roomType, $request->photos());

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Photos uploaded.')]
            : ['type' => 'error', 'message' => $refusal]);

        return back();
    }

    public function reorder(ReorderRoomTypePhotosRequest $request, Property $property, RoomType $roomType, ReorderRoomTypePhotos $reorderPhotos): RedirectResponse
    {
        if (! $reorderPhotos($request->user(), $roomType, $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The photos changed. Reload the page and try again.')]);
        }

        return back();
    }

    public function destroy(Request $request, Property $property, RoomType $roomType, int $photo, RemoveRoomTypePhoto $removePhoto): RedirectResponse
    {
        Inertia::flash('toast', $removePhoto($request->user(), $roomType, $photo)
            ? ['type' => 'success', 'message' => __('Photo removed.')]
            : ['type' => 'error', 'message' => __('The photo could not be removed. Reload the page and try again.')]);

        return back();
    }
}
