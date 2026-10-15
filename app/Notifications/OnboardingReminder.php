<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds the owner that onboarding has stalled and names the next unfinished step. An owner who has not verified the
 * email gets the setup link instead of the plain link.
 */
class OnboardingReminder extends PropertyNotification
{
    public function __construct(
        public string $propertyName,
        public string $propertyCode,
        public string $stepLabel,
        public string $actionUrl,
        public bool $usesSetupLink,
    ) {
        parent::__construct(NotificationType::OnboardingReminder);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Finish setting up :property', ['property' => $this->propertyName]),
                'propertyName' => $this->propertyName,
            ])
            ->subject(__('Finish setting up :property', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]));

        if ($this->usesSetupLink) {
            return $message
                ->line(__('Set your password and verify your email address to continue setting up :property.', ['property' => $this->propertyName]))
                ->action(__('Set up your account'), $this->actionUrl);
        }

        return $message
            ->line(__('Setup for :property is waiting. The next step is :step.', ['property' => $this->propertyName, 'step' => $this->stepLabel]))
            ->action(__('Continue setup'), $this->actionUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => __('Finish setting up :property', ['property' => $this->propertyName]),
            'body' => __('The next step is :step.', ['step' => $this->stepLabel]),
            'property_code' => $this->propertyCode,
            'url' => $this->actionUrl,
        ];
    }
}
