<?php

namespace App\Actions\Platform;

use App\Enums\OtaCredentialStatus;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarkOtaCredentialProcessed
{
    /**
     * Set the credential to Processed once the team has connected the channel. A row that is already Processed is
     * left alone and logs nothing, so a repeat click is harmless.
     */
    public function __invoke(User $admin, PropertyOtaCredential $credential): void
    {
        DB::transaction(function () use ($admin, $credential): void {
            $credential = PropertyOtaCredential::query()->with('channel')->lockForUpdate()->findOrFail($credential->id);

            if ($credential->status === OtaCredentialStatus::Processed) {
                return;
            }

            $credential->update(['status' => OtaCredentialStatus::Processed]);

            $channel = $credential->channel_name ?? $credential->channel->name;

            activity()
                ->performedOn($credential->property)
                ->causedBy($admin)
                ->event('ota_credential.processed')
                ->withProperties(['credential_id' => $credential->id, 'channel' => $channel])
                ->log("OTA list processed for {$channel}");
        });
    }
}
