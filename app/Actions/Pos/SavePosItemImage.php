<?php

namespace App\Actions\Pos;

use App\Models\PosItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Store, replace or remove the one photo of a menu item, on the public media disk so the register and the guest QR menu
 * can show it. Every file gets a new name, so no browser shows a stale photo. See docs/34-native-pos/02-menu-catalog.md.
 */
class SavePosItemImage
{
    public function __invoke(User $actor, Property $property, PosItem $item, ?UploadedFile $file): PosActionResult
    {
        if ($item->property_id !== $property->id) {
            return PosActionResult::refused('item_invalid');
        }

        if ($file === null) {
            $item->clearMediaCollection(PosItem::IMAGE);
        } else {
            $item->addMedia($file)->usingFileName(Str::lower((string) Str::ulid()).'.'.$file->extension())->toMediaCollection(PosItem::IMAGE);
        }

        activity()->performedOn($item)->causedBy($actor)->event('pos.item_image_saved')
            ->withProperties(['property_id' => $property->id])
            ->log($file === null ? 'POS item photo removed' : 'POS item photo uploaded');

        return PosActionResult::saved($item->refresh());
    }
}
