<?php

namespace App\Actions\Folio;

/**
 * What `SaveOldFolios` returns: the number of lines written (`lineCount`, of which `changedCount` replaced an older line),
 * the number of lines taken back with no replacement (`removedCount`), or a refusal code. A refusal from a row names that row
 * (`rowKey`). A repeated form returns `replayed` and writes nothing.
 */
class SaveOldFoliosResult
{
    public const REFUSAL_NOTHING_TO_SAVE = 'nothing_to_save';

    public const REFUSAL_TOO_MANY_ROWS = 'too_many_rows';

    public const REFUSAL_DATE_NOT_BEFORE_GO_LIVE = 'date_not_before_go_live';

    public const REFUSAL_UNIT_NOT_FOUND = 'unit_not_found';

    public const REFUSAL_FOLIO_NOT_FOUND = 'folio_not_found';

    public const REFUSAL_ITEM_REQUIRED = 'item_required';

    public const REFUSAL_DESCRIPTION_REQUIRED = 'description_required';

    public const int MAX_ROWS = 500;

    private function __construct(
        public readonly int $lineCount,
        public readonly bool $replayed,
        public readonly ?string $refusal,
        public readonly ?string $rowKey = null,
        public readonly int $changedCount = 0,
        public readonly int $removedCount = 0,
    ) {}

    public static function saved(int $lineCount, int $changedCount = 0, int $removedCount = 0): self
    {
        return new self($lineCount, false, null, null, $changedCount, $removedCount);
    }

    public static function replayed(): self
    {
        return new self(0, true, null);
    }

    public static function refused(string $refusal, ?string $rowKey = null): self
    {
        return new self(0, false, $refusal, $rowKey);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
