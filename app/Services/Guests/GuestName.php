<?php

namespace App\Services\Guests;

use Illuminate\Support\Str;

/**
 * Brings guest names to one comparable form, so "Aisyah binti Rahman" and "Rahman, Aisyah" give the same key.
 * The stored name is never changed. Only the key used to find possible duplicates is.
 */
class GuestName
{
    /**
     * Lowercase, accents removed, punctuation dropped, Malaysian name particles
     * (bin, binti, bte, bt, b., a/l, a/p) dropped, and the words sorted.
     */
    public function key(string $name): string
    {
        $text = Str::lower(Str::ascii($name));
        $text = (string) preg_replace('~(?<![a-z0-9])(?:binti|bin|bte|bt|b\.|a/l|a/p)(?![a-z0-9])~', ' ', $text);
        $words = array_filter(explode(' ', (string) preg_replace('~[^a-z0-9]+~', ' ', $text)));

        if ($words === []) {
            return Str::lower(trim($name));
        }

        sort($words);

        return mb_substr(implode(' ', $words), 0, 150);
    }
}
