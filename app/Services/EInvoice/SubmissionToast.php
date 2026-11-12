<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;

/**
 * The toast after staff send an e-invoice: what LHDN answered, in plain words. A sent document can come back
 * accepted, still being checked, or rejected on the spot; only the first two are a success.
 */
class SubmissionToast
{
    public function __construct(private LhdnRejectionReasons $rejectionReasons) {}

    /**
     * @return array{type: string, message: string, description?: string}
     */
    public function for(EInvoiceSubmission $submission): array
    {
        $document = $this->document($submission);

        return match ($submission->status) {
            EInvoiceStatus::Valid => ['type' => 'success', 'message' => __('LHDN accepted :document.', ['document' => $document])],
            EInvoiceStatus::Invalid => $this->rejected($submission, $document),
            EInvoiceStatus::Pending => $submission->submitted_at !== null && empty($submission->uuid) ? [
                'type' => 'warning',
                'message' => __('We lost the connection while sending :document.', ['document' => $document]),
                'description' => __('LHDN may have it. Do not send it again: we are checking with LHDN, and the status updates by itself within 15 minutes.'),
            ] : [
                'type' => 'info',
                'message' => __(':Document is ready but was not sent.', ['document' => $document]),
                'description' => __('Press Check status on the e-invoice in a few minutes.'),
            ],
            default => [
                'type' => 'success',
                'message' => __(':Document sent to LHDN.', ['document' => $document]),
                'description' => __('LHDN is still checking it. The status updates by itself in a few minutes.'),
            ],
        };
    }

    /**
     * The toast after a monthly summary run: its one part as `for` reads it, or a count of the parts, with what LHDN
     * rejected or what is still waiting for LHDN, and how many stays over RM10,000 were left out.
     *
     * @param  list<EInvoiceSubmission>  $parts
     * @return array{type: string, message: string, description?: string}
     */
    public function forMonthlySummary(array $parts, int $leftOut): array
    {
        $leftOutNote = $leftOut === 0 ? null : trans_choice(':count stay over RM10,000 was left out. It needs its own e-invoice.|:count stays over RM10,000 were left out. Each needs its own e-invoice.', $leftOut, ['count' => $leftOut]);
        $count = count($parts);
        $rejected = count(array_filter($parts, fn (EInvoiceSubmission $part): bool => $part->status === EInvoiceStatus::Invalid));
        $waiting = count(array_filter($parts, fn (EInvoiceSubmission $part): bool => SubmissionStatusBadge::isUnconfirmed($part)));

        $toast = match (true) {
            $count === 0 => ['type' => 'info', 'message' => __('No stays to send for that month. They are filed already, or none closed.')],
            $count === 1 => $this->for($parts[0]),
            $rejected > 0 => ['type' => 'error', 'message' => __('LHDN rejected :rejected of the :count parts of the monthly summary.', ['rejected' => $rejected, 'count' => $count]), 'description' => __('Open them in the list to see why.')],
            $waiting > 0 => ['type' => 'warning', 'message' => __('We lost the connection while sending :waiting of the :count parts of the monthly summary.', ['waiting' => $waiting, 'count' => $count]), 'description' => __('LHDN may have them. Do not send them again: we are checking with LHDN, and the status updates by itself within 15 minutes.')],
            default => ['type' => 'success', 'message' => __('Monthly summary sent to LHDN in :count parts.', ['count' => $count])],
        };

        if ($leftOutNote !== null) {
            $toast['description'] = trim(($toast['description'] ?? '').' '.$leftOutNote);
        }

        return $toast;
    }

    /**
     * @return array{type: string, message: string, description: string}
     */
    private function rejected(EInvoiceSubmission $submission, string $document): array
    {
        $reason = $this->rejectionReasons->for($submission)[0] ?? null;

        return [
            'type' => 'error',
            'message' => __('LHDN rejected :document. Nothing was filed.', ['document' => $document]),
            'description' => $reason === null
                ? __('Open the e-invoice to see why.')
                : $reason['message'].' '.$reason['fix'],
        ];
    }

    private function document(EInvoiceSubmission $submission): string
    {
        return match (true) {
            $submission->is_consolidated => __('monthly summary :number', ['number' => $submission->internal_id]),
            $submission->document_type === EInvoiceDocumentType::CreditNote => __('credit note :number', ['number' => $submission->internal_id]),
            $submission->document_type === EInvoiceDocumentType::RefundNote => __('refund note :number', ['number' => $submission->internal_id]),
            default => __('e-invoice :number', ['number' => $submission->internal_id]),
        };
    }
}
