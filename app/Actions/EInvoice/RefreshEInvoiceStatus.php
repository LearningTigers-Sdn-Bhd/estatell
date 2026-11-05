<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class RefreshEInvoiceStatus
{
    public function __invoke(EInvoiceSubmission $submission): EInvoiceSubmission
    {
        if (empty($submission->uuid)) {
            return $submission;
        }

        $setting = $submission->property->eInvoiceSetting;
        if ($setting === null) {
            return $submission;
        }

        try {
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
        } catch (MyInvoisApiException $e) {
            Log::warning("MyInvois API error while refreshing status for submission {$submission->id}: {$e->getMessage()}");
        }

        return $submission;
    }
}
