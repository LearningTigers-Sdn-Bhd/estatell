<?php

namespace App\Services\Registration;

use App\Enums\Locale;

/**
 * Everything the registration card PDF shows, already formatted, so the Blade view does no lookups.
 * A blank form has no signature, and its items are not ticked.
 */
class RegistrationCardDocument
{
    /**
     * @param  list<array{section: string, label: string, value: string}>  $fields  `section` is a `RegistrationCardFieldGroup` value
     * @param  bool|null  $marketing  the offers line: null hides it, true shows it ticked
     * @param  list<array{text: string, ticked: bool}>  $items
     */
    public function __construct(
        public readonly Locale $locale,
        public readonly string $propertyName,
        public readonly ?string $number,
        public readonly array $fields,
        public readonly string $termsHtml,
        public readonly array $items,
        public readonly ?string $signerName,
        public readonly ?string $signedAt,
        public readonly ?string $signatureDataUri,
        public readonly ?bool $marketing = null,
    ) {}

    /** True when any text needs Noto Sans SC, which DejaVu Sans does not cover. */
    public function needsCjk(): bool
    {
        $text = $this->termsHtml.implode('', array_column($this->items, 'text')).implode('', array_column($this->fields, 'value'));

        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    }
}
