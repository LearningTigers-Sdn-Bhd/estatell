<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;

class RefreshEInvoiceStatus
{
    /** A Pending document never sent (no send time) this long after it was made was left behind, in minutes. */
    public const int NEVER_SENT_AFTER_MINUTES = 2;

    /**
     * How long after a lost answer an empty look-up is reported, in minutes. LHDN's duplicate check covers the same
     * 10 minutes (Submit Documents API). LHDN does not document how soon a new document shows in its search, so an
     * empty look-up is reported as "LHDN has no record of it", with staff told to check the MyInvois portal before
     * sending again, not as proof that it never arrived.
     */
    public const int NOT_FOUND_AFTER_MINUTES = 10;

    public function __construct(private SaveBuyerToGuest $saveBuyerToGuest) {}

    /**
     * Read the document's status from LHDN and record Valid, Invalid or Cancelled. Anything else leaves it unchanged.
     * A Pending document whose send answer was lost is first looked up by its number among the documents sent around
     * its send time (`searchSentDocuments`). See docs/31-einvoice/01-lhdn-myinvois.md#unconfirmed-sends.
     *
     * @throws MyInvoisApiException when LHDN refuses the read, so the caller can show or retry it.
     */
    public function __invoke(EInvoiceSubmission $submission): EInvoiceSubmission
    {
        $setting = $submission->property->eInvoiceSetting;
        if ($setting === null) {
            return $submission;
        }

        if (empty($submission->uuid)) {
            return $submission->status === EInvoiceStatus::Pending ? $this->findUnconfirmed($submission) : $submission;
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

    /**
     * A Pending document with no LHDN id. Never sent (no send time): after a short wait it is marked not sent, so
     * staff can send it. Sent but its answer was lost: look for it by number; once found it is read like any other.
     */
    private function findUnconfirmed(EInvoiceSubmission $submission): EInvoiceSubmission
    {
        if ($submission->submitted_at === null) {
            if ($submission->created_at !== null && $submission->created_at->diffInMinutes(now()) >= self::NEVER_SENT_AFTER_MINUTES) {
                $submission->status = EInvoiceStatus::Invalid;
                $submission->error_details = ['message' => 'Not sent to LHDN.', 'code' => 0, 'not_sent' => true];
                $submission->save();
            }

            return $submission;
        }

        $sentAt = CarbonImmutable::instance($submission->submitted_at);
        $client = MyInvoisClientFactory::forSetting($submission->property->eInvoiceSetting);
        // LHDN received it during the call (30 seconds at most) if at all, so a short window around the send time
        // keeps the result inside LHDN's page of 100 and its 31-day limit; the minutes either side cover clock drift.
        $windowEnd = now()->min($sentAt->addMinutes(30));
        $found = collect($client->searchSentDocuments($sentAt->subMinutes(2), $windowEnd, $submission->document_type->value))
            ->filter(fn (array $document): bool => (string) ($document['internalId'] ?? '') === $submission->internal_id && ! empty($document['uuid']))
            ->sortByDesc(fn (array $document): string => (string) ($document['dateTimeReceived'] ?? ''))
            ->first();

        if ($found !== null) {
            $submission->uuid = (string) $found['uuid'];
            $submission->submission_uid = $found['submissionUID'] ?? $found['submissionUid'] ?? $submission->submission_uid;
            $submission->long_id = $found['longId'] ?? $submission->long_id;
            $submission->status = EInvoiceStatus::Submitted;
            $submission->save();

            return $this($submission);
        }

        if ($sentAt->diffInMinutes(now()) >= self::NOT_FOUND_AFTER_MINUTES) {
            $submission->status = EInvoiceStatus::Invalid;
            $submission->error_details = ['message' => 'LHDN has no record of it.', 'code' => 0, 'not_found_at_lhdn' => true, 'send_error' => $submission->error_details];
            $submission->save();
        }

        return $submission;
    }
}
