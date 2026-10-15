<?php

namespace App\Services\Platform;

use App\Data\Navigation\CommandResultData;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class PropertyCommandSearch
{
    /** @return Builder<Property> */
    private function visibleTo(User $user): Builder
    {
        return Property::query()->when(! $user->is_admin, fn (Builder $query) => $query->where('partner_id', $user->partner_id));
    }

    /** @return list<CommandResultData> */
    public function search(User $user, string $text): array
    {
        $prefix = addcslashes($text, '\\%_').'%';

        return $this->visibleTo($user)->select('id', 'code', 'name', 'city', 'status', 'partner_id')
            ->where(fn (Builder $query) => $query->where('code', 'like', mb_strtoupper($prefix))->orWhereLike('name', $prefix)->orWhereLike('city', $prefix))
            ->orderByRaw('code = ? desc', [mb_strtoupper($text)])->orderBy('name')->orderBy('id')->limit(5)->get()
            ->map(fn (Property $property): CommandResultData => CommandResultData::fromProperty($property, $user->is_admin, Gate::forUser($user)->allows('viewAs', $property)))->all();
    }

    public function forReference(User $user, string $text): ?Property
    {
        if (! $user->is_admin || preg_match('/^([A-Z]{3,4}[0-9]*)-[0-9]{3,8}$/', mb_strtoupper($text), $match) !== 1) {
            return null;
        }

        return $this->visibleTo($user)->whereHas('documentPrefixHistories', fn (Builder $history) => $history->where('prefix', $match[1]))->first();
    }
}
