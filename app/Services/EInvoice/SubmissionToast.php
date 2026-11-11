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
            default => [
                'type' => 'success',
                'message' => __(':Document sent to LHDN.', ['document' => $document]),
                'description' => __('LHDN is still checking it. The status updates by itself in a few minutes.'),
            ],
        };
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
