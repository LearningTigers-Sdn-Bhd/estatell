<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;

class RefreshEInvoiceStatus
{
    public function __construct(private SaveBuyerToGuest $saveBuyerToGuest) {}

    /**
     * Read the document's status from LHDN and record Valid, Invalid or Cancelled. Anything else leaves it unchanged.
     *
     * @throws MyInvoisApiException when LHDN refuses the read, so the caller can show or retry it.
     */
    public function __invoke(EInvoiceSubmission $submission): EInvoiceSubmission
    {
        if (empty($submission->uuid)) {
            return $submission;
        }

        $setting = $submission->property->eInvoiceSetting;
        if ($setting === null) {
            return $submission;
        }

        $client = MyInvoisClientFactory::forSetting($setting);
        $details = $client->getDocumentDetails($submission->uuid);

        $statusStr = strtolower((string) ($details['status'] ?? ''));

        if ($statusStr === 'valid') {
            $submission->status = EInvoiceStatus::Valid;
            $submission->long_id = $details['longId'] ?? $submission->long_id;
            $submission->validated_at = isset($details['dateTimeValidated'])
                ? CarbonImmutable::parse($details['dateTimeValidated'])
                : CarbonImmutable::now();
            $submission->raw_response = array_merge($submission->raw_response ?? [], $details);
            $submission->save();

            ($this->saveBuyerToGuest)($submission);
        } elseif ($statusStr === 'invalid') {
            $submission->status = EInvoiceStatus::Invalid;
            $submission->error_details = $details['error'] ?? $details;
            $submission->raw_response = array_merge($submission->raw_response ?? [], $details);
            $submission->save();
        } elseif ($statusStr === 'cancelled') {
            $submission->status = EInvoiceStatus::Cancelled;
            $submission->cancelled_at = CarbonImmutable::now();
            $submission->save();
        }

        return $submission;
    }
}
