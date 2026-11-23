<?php

namespace App\Actions\Pos;

use App\Models\PosPosting;

/**
 * What the POS posting Actions return: the posting row, or a refusal code when nothing was written. A receipt that
 * cannot post yet is not a refusal: it comes back as a PendingReview posting.
 */
class PosPostingResult
{
    public const REFUSAL_NOT_PENDING = 'not_pending';

    public const REFUSAL_NOTE_REQUIRED = 'note_required';

    public const REFUSAL_UNIT_NOT_FOUND = 'unit_not_found';

    public const REFUSAL_ATTACH_NOT_SUPPORTED = 'attach_not_supported';

    public const REFUSAL_ACTION_INVALID = 'action_invalid';

    private function __construct(
        public readonly ?PosPosting $posting,
        public readonly bool $replayed,
        public readonly ?string $refusal,
    ) {}

    public static function done(PosPosting $posting): self
    {
        return new self($posting, false, null);
    }

    public static function replayed(PosPosting $posting): self
    {
        return new self($posting, true, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, false, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
