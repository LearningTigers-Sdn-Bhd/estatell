<?php

namespace App\Actions\Pos;

use App\Models\PosStaffPin;
use App\Models\Property;
use App\Models\User;
use App\Notifications\PosPinInvitation;
use Illuminate\Support\Facades\Cache;

/**
 * A manager asks a staff member to set their POS PIN: the staff member gets an email with the link to their POS PIN page.
 * Nobody but the staff member ever sees or sets the PIN. Refuses a staff member who is not active in this property
 * (`staff_invalid`), one who already has a PIN (`pin_already_set`), and a second invitation inside 30 minutes
 * (`invite_recently_sent`), so the mail cannot be used to spam a colleague. A refusal sends nothing.
 */
class InvitePosStaffToSetPin
{
    public const int COOLDOWN_MINUTES = 30;

    public function __invoke(User $actor, Property $property, User $target): PosActionResult
    {
        if ($target->property_id !== $property->id || $target->deactivated_at !== null) {
            return PosActionResult::refused('staff_invalid');
        }

        if (PosStaffPin::query()->where('property_id', $property->id)->where('user_id', $target->id)->exists()) {
            return PosActionResult::refused('pin_already_set');
        }

        if (! Cache::add('pos-pin-invite:'.$property->id.':'.$target->id, true, now()->addMinutes(self::COOLDOWN_MINUTES))) {
            return PosActionResult::refused('invite_recently_sent');
        }

        $target->notify(new PosPinInvitation($property->name, $actor->name, route('account.pos-pin.edit')));

        activity()->performedOn($property)->causedBy($actor)->event('pos.pin_invited')->withProperties(['user_id' => $target->id])->log('POS PIN invitation sent');

        return PosActionResult::saved($target);
    }
}
