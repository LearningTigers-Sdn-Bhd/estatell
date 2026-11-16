<?php

namespace App\Actions\Platform;

use App\Enums\ContactEnquiryStatus;
use App\Models\ContactEnquiry;
use App\Notifications\ContactEnquiryReceived;
use Illuminate\Support\Facades\Notification;

class SubmitContactEnquiry
{
    /**
     * Save an enquiry from the public form, then tell the sales inbox. The row is saved first, so a failed
     * email loses nothing.
     *
     * @param  array<string, mixed>  $data  The validated form fields: hotel_name, location, name, email, phone, room_count, message.
     */
    public function __invoke(array $data): ContactEnquiry
    {
        $enquiry = ContactEnquiry::query()->create([...$data, 'status' => ContactEnquiryStatus::New]);

        Notification::route('mail', (string) config('estatell.contact_notify_email'))
            ->notify(new ContactEnquiryReceived($enquiry));

        return $enquiry;
    }
}
