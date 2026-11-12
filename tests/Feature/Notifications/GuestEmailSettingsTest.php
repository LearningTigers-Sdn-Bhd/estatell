<?php

use App\Enums\GuestEmail;
use App\Enums\PermissionEnum;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Models\PropertyGuestEmail;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

function guestEmailOwner(): User
{
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(EInvoicing::class);

    return $owner;
}

it('shows the default e-invoice email until the property writes its own', function () {
    $owner = guestEmailOwner();

    $this->actingAs($owner)
        ->get(route('settings.emails.edit', $owner->property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/emails')
            ->where('emails.0.value', 'e_invoice')
            ->where('emails.0.subject', GuestEmail::EInvoice->defaultSubject())
            ->where('emails.0.customised', false)
            ->has('emails.0.placeholders', 5));
});

it('saves the property wording and logs it', function () {
    $owner = guestEmailOwner();

    $this->actingAs($owner)
        ->put(route('settings.emails.update', [$owner->property->code, 'e_invoice']), [
            'subject' => 'Your receipt from {hotel_name}',
            'body' => "Hi {guest_name},\n\nThanks for staying.",
        ])
        ->assertInertiaFlash('toast.type', 'success');

    expect(PropertyGuestEmail::query()->where('property_id', $owner->property_id)->sole())
        ->subject->toBe('Your receipt from {hotel_name}')
        ->and(Activity::query()->where('event', 'guest_email.updated')->count())->toBe(1);
});

it('refuses a placeholder the email does not know', function () {
    $owner = guestEmailOwner();

    $this->actingAs($owner)
        ->put(route('settings.emails.update', [$owner->property->code, 'e_invoice']), [
            'subject' => 'Your e-invoice',
            'body' => 'Hi {guestname}',
        ])
        ->assertSessionHasErrors(['body' => 'Unknown placeholder: {guestname}. Use only the placeholders listed below.']);

    expect(PropertyGuestEmail::query()->count())->toBe(0);
});

it('resets to the default text', function () {
    $owner = guestEmailOwner();
    PropertyGuestEmail::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->delete(route('settings.emails.reset', [$owner->property->code, 'e_invoice']))
        ->assertInertiaFlash('toast.type', 'success');

    expect(PropertyGuestEmail::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'guest_email.reset')->count())->toBe(1);
});

it('hides the page without e-invoicing, from staff without settings access and from other properties', function () {
    $owner = guestEmailOwner();
    $frontDesk = User::factory()->for($owner->property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $other = Property::factory()->create();

    $this->actingAs($frontDesk)->get(route('settings.emails.edit', $owner->property->code))->assertForbidden();
    $this->actingAs($owner)->get(route('settings.emails.edit', $other->code))->assertNotFound();

    Feature::for($owner->property)->deactivate(EInvoicing::class);
    $this->actingAs($owner)->get(route('settings.emails.edit', $owner->property->code))->assertNotFound();
});
