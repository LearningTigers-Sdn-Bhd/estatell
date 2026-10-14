<?php

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function registerOwner(array $overrides = []): array
{
    return [
        'name' => 'Aisyah Rahim',
        'email' => 'aisyah@pulau.my',
        'password' => 'password',
        'password_confirmation' => 'password',
        'property_name' => 'Pulau Inn',
        ...$overrides,
    ];
}

test('an Active partner link opens the register page with the partner name', function (bool $canCreate) {
    $partner = Partner::factory()->create(['name' => 'Andaman Stays', 'can_create_properties' => $canCreate]);

    $this->get(route('join', $partner->signup_code))->assertRedirect(route('register'));

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/register')
        ->where('partnerName', 'Andaman Stays')
        ->where('notice', null)
    );
})->with([true, false]);

test('the link registers an owner and a property linked to the partner whether the switch is on or off', function (bool $canCreate) {
    $partner = Partner::factory()->create(['code_prefix' => 'AS', 'can_create_properties' => $canCreate]);

    $this->get(route('join', strtolower($partner->signup_code)));
    $this->post(route('register.store'), registerOwner())->assertRedirect();

    $owner = User::query()->where('email', 'aisyah@pulau.my')->sole();

    expect($owner->property->code)->toBe('AS10101')
        ->and($owner->property->partner_id)->toBe($partner->id)
        ->and(session()->has('join_partner_id'))->toBeFalse();
})->with([true, false]);

test('the partner survives a validation error on the register form', function () {
    $partner = Partner::factory()->create(['name' => 'Andaman Stays']);

    $this->get(route('join', $partner->signup_code));
    $this->post(route('register.store'), registerOwner(['property_name' => '']))->assertSessionHasErrors('property_name');

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('partnerName', 'Andaman Stays'));

    $this->post(route('register.store'), registerOwner());

    expect(User::query()->where('email', 'aisyah@pulau.my')->sole()->property->partner_id)->toBe($partner->id);
});

test('an unknown, regenerated, invited or suspended link shows the notice and registers an ES property', function (Closure $makeCode) {
    $code = $makeCode();

    $this->get(route('join', $code))->assertRedirect(route('register'));

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->where('partnerName', null)
        ->where('notice', 'This link is no longer active')
    );

    $this->post(route('register.store'), registerOwner());

    $property = User::query()->where('email', 'aisyah@pulau.my')->sole()->property;

    expect($property->code)->toStartWith('ES')
        ->and($property->partner_id)->toBeNull();
})->with([
    'unknown code' => [fn () => 'ZZZZZZ'],
    'regenerated link' => [function () {
        $partner = Partner::factory()->create();
        $old = $partner->signup_code;
        $partner->forceFill(['signup_code' => 'KKKKKK'])->save();

        return $old;
    }],
    'invited partner' => [fn () => Partner::factory()->invited()->create()->signup_code],
    'suspended partner' => [fn () => Partner::factory()->suspended()->create()->signup_code],
]);

test('a partner suspended after the link was opened registers an ES property', function () {
    $partner = Partner::factory()->create();

    $this->get(route('join', $partner->signup_code));
    $partner->update(['status' => PartnerStatus::Suspended, 'suspended_at' => now()]);

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('partnerName', null));

    $this->post(route('register.store'), registerOwner());

    $property = User::query()->where('email', 'aisyah@pulau.my')->sole()->property;

    expect($property->code)->toStartWith('ES')
        ->and($property->partner_id)->toBeNull();
});

test('a dead link forgets an earlier partner link', function () {
    $partner = Partner::factory()->create();

    $this->get(route('join', $partner->signup_code));
    $this->get(route('join', 'ZZZZZZ'));

    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('partnerName', null));
});

test('a plain sign-up shows no partner and registers an ES property', function () {
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->where('partnerName', null)
        ->where('notice', null)
    );

    $this->post(route('register.store'), registerOwner());

    expect(User::query()->where('email', 'aisyah@pulau.my')->sole()->property->code)->toStartWith('ES');
});

test('a signed-in user is sent away from a join link', function () {
    $partner = Partner::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('join', $partner->signup_code))->assertRedirect();
});
