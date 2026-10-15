<?php

namespace App\Services\Guests;

use App\Data\Navigation\CommandResultData;
use App\Models\Guest;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;

class GuestCommandSearch
{
    /** @return Builder<Guest> */
    public function matching(Property $property, string $text): Builder
    {
        $prefix = addcslashes($text, '\\%_').'%';

        return Guest::query()->where('property_id', $property->id)->notMerged()->whereNull('anonymized_at')
            ->where(fn (Builder $query) => $query->whereLike('full_name', $prefix)->orWhereLike('email', $prefix)->orWhereLike('phone', $prefix));
    }

    /** @return list<CommandResultData> */
    public function search(Property $property, string $text): array
    {
        return $this->matching($property, $text)->select('id', 'full_name', 'phone', 'email')
            ->orderByRaw('lower(full_name) = lower(?) desc', [$text])->orderBy('full_name')->orderBy('id')->limit(5)->get()
            ->map(fn (Guest $guest): CommandResultData => CommandResultData::fromGuest($guest, $property))->all();
    }
}
