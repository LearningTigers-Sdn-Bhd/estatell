<?php

namespace App\Services\Staff;

use App\Data\Staff\TemporaryPasswordSlipData;
use Illuminate\Support\Collection;

/**
 * The text an owner pastes into WhatsApp or a chat: one numbered block per person, separated by a blank line.
 */
class TemporaryPasswordMessage
{
    /**
     * @param  Collection<int, TemporaryPasswordSlipData>  $slips
     */
    public function for(Collection $slips): string
    {
        return $slips->map(fn (TemporaryPasswordSlipData $slip): string => implode("\n", [
            __('1. Open: :url', ['url' => $slip->loginUrl]),
            __('2. Email: :email', ['email' => $slip->email]),
            __('3. Temp password: :password', ['password' => $slip->temporaryPassword]),
            __('4. Set your own password when asked (expires :date)', ['date' => $slip->expiresOn]),
        ]))->implode("\n\n");
    }
}
