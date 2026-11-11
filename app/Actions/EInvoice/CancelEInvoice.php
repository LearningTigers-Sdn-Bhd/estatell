<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Services\EInvoice\MyInvoisApiException;
use App\Services\EInvoice\MyInvoisClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelEInvoice
{
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

        if (! $submission->isValid() || empty($submission->uuid)) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_CANCELLABLE, __('Only an e-invoice LHDN accepted can be cancelled.'));
        }

        if ($submission->cancellationDeadline() !== null && CarbonImmutable::now()->isAfter($submission->cancellationDeadline())) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_WINDOW_CLOSED, __('The 72 hours to cancel have passed. Issue a credit note instead.'));
        }

        if ($submission->adjustments()->where('status', '!=', EInvoiceStatus::Cancelled)->exists()) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_REFERENCED_BY_ADJUSTMENT, __('A credit note corrects this e-invoice, so it cannot be cancelled. Cancel the credit note first.'));
        }

        return DB::transaction(function () use ($submission, $reason, $actor, $setting): EInvoiceResult {
            try {
                $client = MyInvoisClientFactory::forSetting($setting);
                $client->cancelDocument($submission->uuid, $reason);

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
            } catch (MyInvoisApiException $e) {
                Log::error("Failed to cancel e-invoice {$submission->uuid}: {$e->getMessage()}");

                return EInvoiceResult::refused('api_error', "MyInvois cancellation failed: {$e->getMessage()}");
            }
        });
    }
}
