<?php

namespace App\Console\Commands;

use App\Actions\CityLedger\SendAgentPaymentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent-payments:send-reminders')]
#[Description('Email agents their payment schedule, the payments due within a day and the overdue ones')]
class SendAgentPaymentRemindersCommand extends Command
{
    public function handle(SendAgentPaymentReminders $send): int
    {
        $this->info(__(':count payment emails sent.', ['count' => $send()]));

        return self::SUCCESS;
    }
}
