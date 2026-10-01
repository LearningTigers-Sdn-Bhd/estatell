<?php

namespace App\Services\NightAudit;

use App\Enums\DocumentType;
use Illuminate\Support\Str;

/**
 * Builds a document reference: `{PREFIX}-{YY}{T}{NNNNN}`, for example `GRH-26500042`.
 * Same format as wastays: property prefix, two-digit year, one-digit type code, number padded to five digits.
 */
class DocumentReference
{
    private const PAD_LENGTH = 5;

    public function format(string $prefix, int $year, DocumentType $type, int $number): string
    {
        return sprintf(
            '%s-%02d%d%s',
            $prefix,
            $year % 100,
            $type->code(),
            Str::padLeft((string) $number, self::PAD_LENGTH, '0'),
        );
    }
}
