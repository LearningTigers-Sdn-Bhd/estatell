<?php

namespace App\Services\EInvoice;

use App\Data\Shared\BadgeData;
use App\Enums\EInvoiceStatus;
use App\Enums\Tone;
use App\Models\EInvoiceSubmission;

/**
 * The status badge of an e-invoice document, as staff read it. A Pending document that was sent but whose answer was
 * lost reads "Waiting for LHDN", not "Preparing": LHDN may have it, so it must not be sent again. It reads only.
 */
class SubmissionStatusBadge
{
    public const string UNCONFIRMED = 'unconfirmed';

    public function for(EInvoiceSubmission $submission): BadgeData
    {
        if (self::isUnconfirmed($submission)) {
            return new BadgeData(self::UNCONFIRMED, __('Waiting for LHDN'), Tone::Warning);
        }

        return new BadgeData($submission->status->value, $submission->status->label(), $submission->status->tone());
    }

    /**
     * Sent (it has a send time) with no LHDN id: the answer was lost, and the document is being looked up.
     */
    public static function isUnconfirmed(EInvoiceSubmission $submission): bool
    {
        return $submission->status === EInvoiceStatus::Pending && $submission->submitted_at !== null && empty($submission->uuid);
    }
}
