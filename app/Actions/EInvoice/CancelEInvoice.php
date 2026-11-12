<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use App\Services\EInvoice\SubmissionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class CancelEInvoice
{
    public function __construct(private LockEInvoiceRecords $lockEInvoiceRecords, private SubmissionRules $submissionRules) {}

    public function __invoke(EInvoiceSubmission $submission, string $reason, User $actor): EInvoiceResult
    {
        $property = $submission->property;
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! $setting->enabled) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_ENABLED, __('E-invoicing is not enabled for this property.'));
        }

        if (trim($reason) === '') {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_REASON_REQUIRED, __('Enter a reason. LHDN keeps it with the e-invoice.'));
        }

        // Under the e-invoice lock, so a credit note cannot be saved on it while it is being cancelled. It is the one
        // call to LHDN made under the lock: cancelling is rare and takes one call.
        return ($this->lockEInvoiceRecords)($property, function () use ($submission, $reason, $actor, $setting): EInvoiceResult {
            $submission->refresh();

            if ($submission->status !== EInvoiceStatus::Valid || empty($submission->uuid)) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_CANCELLABLE, __('Only an e-invoice LHDN accepted can be cancelled.'));
            }

            $deadline = $this->submissionRules->cancellationDeadline($submission);
            if ($deadline !== null && CarbonImmutable::now()->isAfter($deadline)) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_WINDOW_CLOSED, __('The 72 hours to cancel have passed. Issue a credit note instead.'));
            }

            // A rejected or cancelled note is not on LHDN, so it does not stop the cancel.
            if ($submission->adjustments()->active()->exists()) {
                return EInvoiceResult::refused(EInvoiceResult::REFUSAL_REFERENCED_BY_ADJUSTMENT, __('A credit note corrects this e-invoice, so it cannot be cancelled. Cancel the credit note first.'));
            }

            try {
                MyInvoisClientFactory::forSetting($setting)->cancelDocument($submission->uuid, $reason);
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to cancel e-invoice {$submission->uuid}: {$e->getMessage()}");

                return EInvoiceResult::refused('api_error', "MyInvois cancellation failed: {$e->getMessage()}");
            }

            $submission->status = EInvoiceStatus::Cancelled;
            $submission->cancelled_at = CarbonImmutable::now();
            $submission->cancel_reason = $reason;
            $submission->save();

            activity()
                ->performedOn($submission)
                ->causedBy($actor)
                ->event('einvoice.cancelled')
                ->withProperties([
                    'submission_id' => $submission->id,
                    'uuid' => $submission->uuid,
                    'reason' => $reason,
                ])
                ->log('Cancelled e-invoice on LHDN MyInvois');

            return EInvoiceResult::done($submission);
        });
    }
}
