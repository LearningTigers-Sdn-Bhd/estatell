<?php

namespace App\Notifications;

use App\Models\ContactEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactEnquiryReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContactEnquiry $enquiry)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enquiry = $this->enquiry;

        return (new MailMessage)
            ->markdown('notifications::email', ['mailTitle' => __('New Contact Us enquiry'), 'mailDetails' => [
                __('Hotel') => $enquiry->hotel_name,
                __('Location') => $enquiry->location,
                __('Name') => $enquiry->name,
                __('Email') => $enquiry->email,
                __('Mobile') => $enquiry->phone,
                __('Rooms') => (string) $enquiry->room_count,
            ]])
            ->subject(__('New enquiry from :hotel', ['hotel' => $enquiry->hotel_name]))
            ->replyTo($enquiry->email, $enquiry->name)
            ->greeting(__('Hello,'))
            ->line(__(':name sent an enquiry through the Contact Us form:', ['name' => $enquiry->name]))
            ->line($enquiry->message)
            ->action(__('Open in admin'), route('admin.enquiries.show', $enquiry));
    }
}
