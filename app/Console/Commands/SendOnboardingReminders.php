<?php

namespace App\Console\Commands;

use App\Actions\Onboarding\SendOnboardingReminder;
use App\Services\Onboarding\DueOnboardingReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('onboarding:send-reminders')]
#[Description('Send the onboarding reminders that are due to owners who have stalled')]
class SendOnboardingReminders extends Command
{
    public function handle(DueOnboardingReminders $dueReminders, SendOnboardingReminder $sendReminder): int
    {
        $now = now();

        foreach ($dueReminders->get($now) as $property) {
            $sendReminder($property, $now);
        }

        return self::SUCCESS;
    }
}
