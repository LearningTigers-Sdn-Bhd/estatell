<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use App\Models\User;
use App\Notifications\EInvoiceForGuest;
use App\Services\EInvoice\EInvoiceGuestEmail;
use App\Services\Notifications\MailActorResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;

class EmailEInvoiceToGuest
{
    public function __construct(private EInvoiceGuestEmail $guestEmail) {}

    /**
     * Email the guest their e-invoice, with the property's own wording. Only an e-invoice LHDN accepted has a link the
     * guest can open, so anything else is refused. Logs `einvoice.emailed` with the address.
     */
    public function __invoke(EInvoiceSubmission $submission, string $to, User $actor): EInvoiceResult
    {
        if ($submission->status !== EInvoiceStatus::Valid || $submission->is_consolidated) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_EMAILABLE, __('Only an e-invoice LHDN accepted can be emailed to the guest.'));
        }

        $email = $this->guestEmail->for($submission);
        if ($email['view_url'] === null) {
            return EInvoiceResult::refused(EInvoiceResult::REFUSAL_NOT_EMAILABLE, __('This e-invoice has no LHDN link yet. Press Check status, then try again.'));
        }

        Context::scope(fn () => Notification::route('mail', $to)->notify(new EInvoiceForGuest(
            $email['subject'],
            $email['paragraphs'],
            $email['details'],
            $email['property_name'],
            $email['reply_to'],
            $email['view_url'],
        )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $submission->property_id]);

        activity()
            ->performedOn($submission)
            ->causedBy($actor)
            ->event('einvoice.emailed')
            ->withProperties(['submission_id' => $submission->id, 'property_id' => $submission->property_id, 'to' => $to])
            ->log('Emailed e-invoice to guest');

        return EInvoiceResult::done($submission);
    }
}
