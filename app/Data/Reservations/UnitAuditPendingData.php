<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The warning strip of the Check in, Check out and group check-out forms while the night audit is pending: "Hotel day
 * 20 Oct · Today 21 Oct". Both dates are text for the user (`j M`), so the browser has no date rules. `running` is set
 * while the audit is under way (the business date is Auditing), and the strip then says "Night audit running" in place
 * of "Night audit not run yet". `notice` is the ⓘ line under the stay, or null.
 */
class UnitAuditPendingData extends Data
{
    public function __construct(
        public string $businessDate,
        public string $today,
        public bool $running,
        public ?string $notice,
    ) {}
}
