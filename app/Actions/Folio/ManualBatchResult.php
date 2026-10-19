<?php

namespace App\Actions\Folio;

use App\Enums\ManualRowType;
use App\Models\FolioManualBatch;

/**
 * What `PostManualBatch` returns: the batch, or a refusal code. A refusal from a row names that row (`rowKey`) and its
 * type, so the page shows the message on the row; a refusal of the whole batch or of the close has neither. A repeated
 * form returns the existing batch with `replayed` set. `rooms` names the rooms for the close refusals.
 */
class ManualBatchResult
{
    public const REFUSAL_NOTHING_TO_POST = 'nothing_to_post';

    public const REFUSAL_TOO_MANY_ROWS = 'too_many_rows';

    public const REFUSAL_UNIT_REQUIRED = 'unit_required';

    public const int MAX_ROWS = 100;

    /**
     * @param  list<string>  $rooms
     */
    private function __construct(
        public readonly ?FolioManualBatch $batch,
        public readonly bool $replayed,
        public readonly ?string $refusal,
        public readonly ?string $rowKey = null,
        public readonly ?ManualRowType $rowType = null,
        public readonly array $rooms = [],
        public readonly bool $closed = false,
    ) {}

    public static function posted(FolioManualBatch $batch, bool $closed): self
    {
        return new self($batch, false, null, closed: $closed);
    }

    public static function replayed(FolioManualBatch $batch): self
    {
        return new self($batch, true, null);
    }

    /**
     * @param  list<string>  $rooms
     */
    public static function refused(string $refusal, ?string $rowKey = null, ?ManualRowType $rowType = null, array $rooms = []): self
    {
        return new self(null, false, $refusal, $rowKey, $rowType, $rooms);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
