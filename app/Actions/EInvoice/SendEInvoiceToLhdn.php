<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\EInvoiceSubmission;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\MyInvoisLoginFailed;
use Illuminate\Support\Facades\Log;

/**
 * Send one saved Pending document to LHDN and record the answer. It runs outside any database transaction, so the
 * record of what was sent survives whatever happens after the call. The send time is saved before the call: a Pending
 * document with a send time and no LHDN id is one whose answer was lost (a timeout, a dropped connection or an LHDN
 * server error), and LHDN may have it. Such a document stays Pending, keeps blocking a second send, and
 * `RefreshEInvoiceStatus` looks it up by its number. Only a failed login or an LHDN refusal of the request marks it
 * Rejected, because then LHDN has nothing. See docs/31-einvoice/01-lhdn-myinvois.md#unconfirmed-sends.
 */
class SendEInvoiceToLhdn
{
    /** How long to wait before the first look-up of a document whose answer was lost, in seconds. */
    public const int FIRST_LOOKUP_DELAY = 60;

    public function __construct(private RefreshEInvoiceStatus $refreshEInvoiceStatus) {}

    /**
     * @param  array{format: string, document: string, documentHash: string, codeNumber: string}  $built
     */
    public function __invoke(EInvoiceSubmission $submission, array $built): EInvoiceSubmission
    {
        // Built before the send time is saved, so a setup error cannot pass for a send whose answer was lost.
        $client = MyInvoisClientFactory::forSetting($submission->property->eInvoiceSetting);
        $submission->forceFill(['submitted_at' => now()])->save();

        try {
            $response = $client->submitDocuments([[
                'format' => $built['format'],
                'document' => $built['document'],
                'documentHash' => $built['documentHash'],
                'codeNumber' => $built['codeNumber'],
            ]]);
        } catch (MyInvoisApiException $exception) {
            return $this->failed($submission, $exception);
        }

        $accepted = $response['acceptedDocuments'][0] ?? null;
        $rejected = $response['rejectedDocuments'][0] ?? null;

        $submission->submission_uid = $response['submissionUid'] ?? null;
        $submission->raw_response = $response;

        if ($rejected !== null) {
            $submission->status = EInvoiceStatus::Invalid;
            $submission->error_details = $rejected['error'] ?? $rejected;
            $submission->save();

            return $submission;
        }

        if ($accepted === null || empty($accepted['uuid'])) {
            // LHDN answered without saying what became of the document: find it by its number.
            $submission->save();
            $this->lookUpLater($submission);

            return $submission;
        }

        $submission->uuid = $accepted['uuid'];
        $submission->status = EInvoiceStatus::Submitted;
        $submission->save();

        // LHDN often refuses a status read for the first seconds while it validates. The queued refresh tries
        // again, so an error here leaves the document Submitted.
        try {
            $submission = ($this->refreshEInvoiceStatus)($submission);
        } catch (MyInvoisApiException) {
        }

        if ($submission->status === EInvoiceStatus::Submitted) {
            RefreshEInvoiceStatusJob::dispatch($submission->id);
        }

        return $submission;
    }

    private function failed(EInvoiceSubmission $submission, MyInvoisApiException $exception): EInvoiceSubmission
    {
        Log::warning("E-invoice {$submission->internal_id} send failed ({$exception->statusCode}): {$exception->getMessage()}");

        $submission->error_details = [
            'message' => $exception->getMessage(),
            'code' => $exception->statusCode,
            'body' => $exception->responseBody,
        ];

        if ($this->mayHaveArrived($exception)) {
            $submission->save();
            $this->lookUpLater($submission);

            return $submission;
        }

        // LHDN has nothing: the login failed or LHDN refused the request itself. Nothing was sent.
        $submission->status = EInvoiceStatus::Invalid;
        $submission->submitted_at = $exception instanceof MyInvoisLoginFailed ? null : $submission->submitted_at;
        $submission->save();

        return $submission;
    }

    /**
     * Whether LHDN may hold the document although the call failed: no answer came (timeout or dropped connection,
     * status 0 or 408), LHDN failed on its side (5xx), or it refused the request as a duplicate of one it already has.
     */
    private function mayHaveArrived(MyInvoisApiException $exception): bool
    {
        if ($exception instanceof MyInvoisLoginFailed) {
            return false;
        }

        $code = $exception->statusCode;
        $errorCode = is_array($exception->responseBody) ? (string) data_get($exception->responseBody, 'error.code', data_get($exception->responseBody, 'code', '')) : '';

        return $code === 0 || $code === 408 || $code >= 500 || ($code === 422 && $errorCode === 'DuplicateSubmission');
    }

    private function lookUpLater(EInvoiceSubmission $submission): void
    {
        RefreshEInvoiceStatusJob::dispatch($submission->id)->delay(self::FIRST_LOOKUP_DELAY);
    }
}
