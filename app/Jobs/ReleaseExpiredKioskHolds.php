<?php

namespace App\Jobs;

use App\Actions\Kiosk\ReleaseExpiredKioskHold;
use App\Enums\PaymentStatus;
use App\Models\KioskWalkInHold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseExpiredKioskHolds implements ShouldQueue
{
    use Queueable;

    public function handle(ReleaseExpiredKioskHold $release): void
    {
        KioskWalkInHold::query()->whereNull('released_at')->where('expires_at', '<=', now())
            ->whereHas('stay', fn ($stays) => $stays->where('status', 'confirmed'))
            ->whereDoesntHave('stay.reservation.folios.payments', fn ($payments) => $payments->where('status', PaymentStatus::Completed))
            ->eachById(function (KioskWalkInHold $hold) use ($release): void {
                $release($hold);
            });
    }
}
