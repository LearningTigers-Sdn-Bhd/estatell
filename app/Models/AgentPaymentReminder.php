<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment email sent to an agent: `stage` (deposit, balance, full, or all for the schedule) and `kind` (schedule,
 * reminder, overdue). It only stops the same email going twice.
 *
 * @property int $id
 * @property int $reservation_id
 * @property string $stage
 * @property string $kind
 * @property CarbonImmutable $sent_at
 */
class AgentPaymentReminder extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
