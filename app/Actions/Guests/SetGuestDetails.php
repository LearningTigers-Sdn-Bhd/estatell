<?php

namespace App\Actions\Guests;

use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\Gender;
use App\Enums\WebhookEvent;
use App\Models\Guest;
use App\Models\User;
use App\Services\Guests\GuestName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SetGuestDetails
{
    public function __construct(private GuestName $guestName) {}

    /**
     * Set the guest's name, nationality, date of birth and gender. A null detail is left alone. With `$keepExisting`
     * a detail the guest already has is not replaced (a reader fills blanks only); without it the value wins (the guest
     * corrects their own details). Returns false when the guest was merged into another guest or anonymized.
     * The activity log names the fields that changed, never the values.
     *
     * @param  array{full_name?: string|null, nationality_code?: string|null, date_of_birth?: CarbonImmutable|null, gender?: Gender|null}  $details
     * @param  array<string, mixed>  $context  Extra activity log properties, for example the kiosk.
     */
    public function __invoke(Guest $guest, array $details, bool $keepExisting, ?User $actor = null, array $context = []): bool
    {
        return DB::transaction(function () use ($guest, $details, $keepExisting, $actor, $context): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            foreach (array_filter($details, fn (mixed $value): bool => $value !== null && $value !== '') as $field => $value) {
                if (! $keepExisting || blank($guest->{$field})) {
                    $guest->{$field} = $value;
                }
            }

            $changed = array_keys($guest->getDirty());

            if ($changed === []) {
                return true;
            }

            $guest->name_key = $this->guestName->key($guest->full_name);
            $guest->save();

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.updated')
                ->withProperties(['property_id' => $guest->property_id, 'changed' => $changed, ...$context])
                ->log('Guest updated');

            app(TriggerWebhook::class)(WebhookEvent::GuestUpdated, $guest->property_id, Guest::class, $guest->id);

            return true;
        });
    }
}
