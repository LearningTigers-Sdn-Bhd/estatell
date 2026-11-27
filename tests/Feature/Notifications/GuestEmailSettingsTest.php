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

it('lists the emails the property sends, grouped by module', function () {
    $owner = guestEmailOwner();
    PropertyGuestEmail::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->get(route('settings.emails.index', $owner->property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/emails/index')
            ->has('emails', 1)
            ->where('emails.0.value', 'e_invoice')
            ->where('emails.0.module', 'E-invoicing')
            ->where('emails.0.customised', true));
});

it('shows the default e-invoice email with a preview rendered from the mail template', function () {
    $owner = guestEmailOwner();

    $this->actingAs($owner)
        ->get(route('settings.emails.edit', [$owner->property->code, 'e_invoice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/emails/edit')
            ->where('email.subject', GuestEmail::EInvoice->defaultSubject())
            ->where('email.customised', false)
            ->has('email.placeholders', 5)
            ->where('preview.subject', 'Your e-invoice from '.$owner->property->name)
            ->where('preview.html', fn (string $html): bool => str_contains($html, 'Dear Ahmad Razak,')
                && str_contains($html, 'INV-2026-0012')
                && str_contains($html, 'View e-invoice')
                && str_contains($html, e($owner->property->name).' · estatell')));
});

it('previews the wording being edited without saving it', function () {
    $owner = guestEmailOwner();

    $this->actingAs($owner)
        ->postJson(route('settings.emails.preview', [$owner->property->code, 'e_invoice']), [
            'subject' => 'Receipt {invoice_number}',
            'body' => "Hello {guest_name}.\n\nSee you again.",
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'Receipt INV-2026-0012')
        ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Hello Ahmad Razak.') && str_contains($html, 'See you again.'));

    expect(PropertyGuestEmail::query()->count())->toBe(0);
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

it('hides the pages without e-invoicing, from staff without settings access and from other properties', function () {
    $owner = guestEmailOwner();
    $frontDesk = User::factory()->for($owner->property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $other = Property::factory()->create();

    $this->actingAs($frontDesk)->get(route('settings.emails.index', $owner->property->code))->assertForbidden();
    $this->actingAs($frontDesk)->postJson(route('settings.emails.preview', [$owner->property->code, 'e_invoice']), [])->assertForbidden();
    $this->actingAs($owner)->get(route('settings.emails.index', $other->code))->assertNotFound();

    Feature::for($owner->property)->deactivate(EInvoicing::class);
    $this->actingAs($owner)->get(route('settings.emails.index', $owner->property->code))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.emails.edit', [$owner->property->code, 'e_invoice']))->assertNotFound();
    $this->actingAs($owner)->postJson(route('settings.emails.preview', [$owner->property->code, 'e_invoice']), [])->assertNotFound();
});
