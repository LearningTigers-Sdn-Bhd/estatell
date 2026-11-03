<?php

namespace App\Actions\EInvoice;

use App\Models\EInvoiceSubmission;

class EInvoiceResult
{
    public const string REFUSAL_NOT_ENABLED = 'e_invoicing_not_enabled';

    public const string REFUSAL_FOLIO_NOT_CLOSED = 'folio_not_closed';

    public const string REFUSAL_CREDENTIALS_MISSING = 'credentials_missing';

    public const string REFUSAL_ALREADY_EXISTS = 'submission_already_exists';

    public const string REFUSAL_WINDOW_CLOSED = 'cancellation_window_closed';

    public const string REFUSAL_REFERENCED_BY_ADJUSTMENT = 'referenced_by_adjustment';

    public const string REFUSAL_NOT_CANCELLABLE = 'not_cancellable';

    public const string REFUSAL_REASON_REQUIRED = 'reason_required';

    public const string REFUSAL_ORIGINAL_NOT_VALID = 'original_not_valid';

    private function __construct(
        public readonly bool $ok,
        public readonly ?EInvoiceSubmission $submission = null,
        public readonly ?string $refusal = null,
        public readonly ?string $message = null
    ) {}

    public static function done(EInvoiceSubmission $submission): self
    {
        return new self(true, $submission);
    }

    public static function refused(string $refusal, ?string $message = null): self
    {
        return new self(false, null, $refusal, $message);
    }
}
