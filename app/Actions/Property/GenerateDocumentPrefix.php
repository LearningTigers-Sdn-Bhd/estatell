<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\PropertyDocumentPrefixHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateDocumentPrefix
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 4;

    private const FALLBACK = 'EST';

    /**
     * Give the property its document prefix, built from its name. A prefix that is already set never changes.
     *
     * A retired prefix stays in the history table, so no other property can ever take it.
     */
    public function __invoke(Property $property): string
    {
        if ($property->document_prefix !== null) {
            return $property->document_prefix;
        }

        return DB::transaction(function () use ($property): string {
            $prefix = $this->uniquePrefix($this->base($property->name));

            $property->document_prefix = $prefix;
            $property->save();

            PropertyDocumentPrefixHistory::query()->create(['property_id' => $property->id, 'prefix' => $prefix]);

            return $prefix;
        });
    }

    /**
     * Initials of three or more words, the first letters of two words, or the start of a single word.
     */
    private function base(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Z\s]/', '', Str::ascii(Str::upper($name)))), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return self::FALLBACK;
        }

        $base = match (true) {
            count($words) >= self::MIN_LENGTH => Str::substr(implode('', array_map(fn (string $word): string => $word[0], $words)), 0, self::MAX_LENGTH),
            count($words) === 2 => $words[0][0].($words[0][1] ?? $words[1][1] ?? 'X').$words[1][0],
            default => Str::substr($words[0], 0, self::MAX_LENGTH),
        };

        return Str::padRight($base, self::MIN_LENGTH, 'X');
    }

    private function uniquePrefix(string $base): string
    {
        $candidate = $base;

        for ($suffix = 2; $this->isTaken($candidate); $suffix++) {
            $candidate = $base.$suffix;
        }

        return $candidate;
    }

    private function isTaken(string $prefix): bool
    {
        return Property::query()->where('document_prefix', $prefix)->exists()
            || PropertyDocumentPrefixHistory::query()->where('prefix', $prefix)->exists();
    }
}
