<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\Tag;

class SyncGuestTags
{
    /**
     * Replace the guest's tags with the given names. A name that matches an existing tag of the property,
     * ignoring case, reuses it; any other name creates a tag.
     *
     * @param  list<string>  $names
     */
    public function __invoke(Guest $guest, array $names): void
    {
        $tagIds = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $tag = Tag::query()
                ->where('property_id', $guest->property_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($tag === null) {
                $tag = new Tag(['name' => $name]);
                $tag->property_id = $guest->property_id;
                $tag->save();
            }

            $tagIds[] = $tag->id;
        }

        $guest->tags()->sync($tagIds);
    }
}
