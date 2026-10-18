<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an existing company user that a property wants to link them to a company as its portal login. The user accepts
 * it in the portal. Company users have no property, so it does not use the property notification preferences and is
 * always sent by mail and in-app. The data is copied in.
 */
class CompanyLinkInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyUserId, public string $propertyName, public string $companyName)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __(':property invited you', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
                'mailDetails' => [__('Property') => $this->propertyName, __('Company') => $this->companyName],
            ])
            ->subject(__(':property invited you to its company portal', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':property set your email as the portal login of :company. Accept the invitation to book and manage stays for :company.', ['property' => $this->propertyName, 'company' => $this->companyName]))
            ->action(__('Open the company portal'), route('portal.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'company_link_invitation',
            'title' => __(':property invited you', ['property' => $this->propertyName]),
            'body' => __('Accept the portal login of :company.', ['company' => $this->companyName]),
            'company_user_id' => $this->companyUserId,
        ];
    }
}
