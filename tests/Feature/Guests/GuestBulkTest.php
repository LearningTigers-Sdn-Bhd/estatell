<?php

use App\Enums\GuestBulkAction;
use App\Enums\PermissionEnum;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('bulk guest actions change each guest and log once per change', function (GuestBulkAction $action, array $initial, string $event, string $field, mixed $expected) {
    $owner = User::factory()->owner()->create();
    $guests = Guest::factory()->for($owner->property)->count(2)->create($initial);
    $ids = $guests->modelKeys();
    $url = route('guests.bulk.'.$action->value, $owner->property->code);
    $payload = ['guest_ids' => $ids, 'reason' => 'Threatened staff', 'confirmation' => 'Confirm'];

    $this->actingAs($owner)->from(route('guests.index', $owner->property->code))->post($url, $payload)
        ->assertRedirect(route('guests.index', $owner->property->code))
        ->assertInertiaFlash('guestBulkResult', ['changedIds' => $ids, 'unchangedIds' => [], 'skippedIds' => []]);

    foreach ($guests as $guest) {
        $guest->refresh();
        expect($guest->getRawOriginal($field))->toBe($expected);
        if ($action === GuestBulkAction::Anonymize) {
            expect($guest->full_name)->toBe("Archived User #{$guest->id}")
                ->and($guest->anonymized_at)->not->toBeNull();
        }
    }
    expect(Activity::query()->where('event', $event)->where('causer_id', $owner->id)->count())->toBe(2);

    $this->post($url, $payload)->assertInertiaFlash('guestBulkResult.changedIds', []);

    expect(Activity::query()->where('event', $event)->count())->toBe(2);
})->with([
    'mark VIP' => [GuestBulkAction::MarkVip, ['is_vip' => false], 'guest.vip_set', 'is_vip', true],
    'unmark VIP' => [GuestBulkAction::UnmarkVip, ['is_vip' => true], 'guest.vip_removed', 'is_vip', false],
    'blacklist' => [GuestBulkAction::Blacklist, ['blacklisted_at' => null], 'guest.blacklisted', 'blacklist_reason', 'Threatened staff'],
    'unblacklist' => [GuestBulkAction::Unblacklist, ['blacklisted_at' => '2026-10-01', 'blacklist_reason' => 'Old reason'], 'guest.unblacklisted', 'blacklist_reason', null],
    'archive' => [GuestBulkAction::Anonymize, ['email' => 'private@example.com'], 'guest.anonymized', 'email', null],
]);

test('bulk blacklist preserves existing reasons and reports unchanged guests separately', function () {
    $owner = User::factory()->owner()->create();
    $existing = Guest::factory()->for($owner->property)->create(['blacklisted_at' => '2026-10-01', 'blacklist_reason' => 'Existing reason']);
    $guest = Guest::factory()->for($owner->property)->create();

    $this->actingAs($owner)->post(route('guests.bulk.blacklist', $owner->property->code), [
        'guest_ids' => [$existing->id, $guest->id], 'reason' => 'New shared reason',
    ])->assertInertiaFlash('guestBulkResult', ['changedIds' => [$guest->id], 'unchangedIds' => [$existing->id], 'skippedIds' => []])
        ->assertInertiaFlash('toast.message', '1 guest blacklisted. 1 already in the requested state.');

    $this->assertDatabaseHas('guests', ['id' => $existing->id, 'blacklist_reason' => 'Existing reason']);
    $this->assertDatabaseHas('guests', ['id' => $guest->id, 'blacklist_reason' => 'New shared reason']);
    expect(Activity::query()->where('event', 'guest.blacklisted')->count())->toBe(1);
});

test('bulk guest actions reject the whole selection when it includes another property', function (GuestBulkAction $action) {
    $owner = User::factory()->owner()->create();
    $local = Guest::factory()->for($owner->property)->create(['is_vip' => false]);
    $foreign = Guest::factory()->create();
    $original = $local->refresh()->getRawOriginal();

    $this->actingAs($owner)->post(route('guests.bulk.'.$action->value, $owner->property->code), [
        'guest_ids' => [$local->id, $foreign->id], 'reason' => 'Reason', 'confirmation' => 'Confirm',
    ])->assertNotFound();

    expect($local->refresh()->getRawOriginal())->toBe($original)
        ->and(Activity::query()->count())->toBe(0);
})->with(GuestBulkAction::cases());

test('bulk guest actions need the matching permission', function (GuestBulkAction $action) {
    $owner = User::factory()->owner()->create();
    $viewer = User::factory()->for($owner->property)->withPermissions([PermissionEnum::GuestsView])->create();
    $guest = Guest::factory()->for($owner->property)->create();

    $this->actingAs($viewer)->post(route('guests.bulk.'.$action->value, $owner->property->code), [
        'guest_ids' => [$guest->id], 'reason' => 'Reason', 'confirmation' => 'Confirm',
    ])->assertForbidden();

    expect(Activity::query()->count())->toBe(0);
})->with(GuestBulkAction::cases());

test('the guest list sends only bulk actions allowed by the staff permissions', function () {
    $owner = User::factory()->owner()->create();
    $editor = User::factory()->for($owner->property)->withPermissions([PermissionEnum::GuestsView, PermissionEnum::GuestsManage])->create();
    $viewer = User::factory()->for($owner->property)->withPermissions([PermissionEnum::GuestsView])->create();
    $url = route('guests.index', $owner->property->code);

    $this->actingAs($editor)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('bulkActions', [['value' => 'mark-vip', 'label' => 'Mark as VIP'], ['value' => 'unmark-vip', 'label' => 'Unmark as VIP']])
        ->where('maxBulkGuests', 100));
    $this->actingAs($viewer)->get($url)->assertInertia(fn (Assert $page) => $page->where('bulkActions', []));
});

test('closed profiles are skipped without changes or audit entries by every bulk action', function (GuestBulkAction $action) {
    $owner = User::factory()->owner()->create();
    $survivor = Guest::factory()->for($owner->property)->create();
    $archived = Guest::factory()->for($owner->property)->create(['anonymized_at' => now(), 'blacklisted_at' => now(), 'blacklist_reason' => 'Keep']);
    $merged = Guest::factory()->for($owner->property)->create(['merged_into_id' => $survivor->id, 'blacklisted_at' => now(), 'blacklist_reason' => 'Keep']);
    $original = [$archived->refresh()->getRawOriginal(), $merged->refresh()->getRawOriginal()];

    $this->actingAs($owner)->post(route('guests.bulk.'.$action->value, $owner->property->code), [
        'guest_ids' => [$archived->id, $merged->id], 'reason' => 'New reason', 'confirmation' => 'Confirm',
    ])->assertInertiaFlash('guestBulkResult', ['changedIds' => [], 'unchangedIds' => [], 'skippedIds' => [$archived->id, $merged->id]]);

    expect([$archived->refresh()->getRawOriginal(), $merged->refresh()->getRawOriginal()])->toBe($original)
        ->and(Activity::query()->count())->toBe(0);
})->with(GuestBulkAction::cases());

test('bulk archive skips in-house guests and open balances but still archives an eligible guest', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $inHouse = Guest::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->checkedIn()->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $inHouse->id]);
    $payer = Guest::factory()->for($property)->create();
    $folio = Folio::factory()->for($property)->paidBy($payer)->create();
    FolioLine::factory()->for($folio)->create();
    $eligible = Guest::factory()->for($property)->create(['email' => 'remove@example.com']);

    $this->actingAs($owner)->post(route('guests.bulk.anonymize', $property->code), [
        'guest_ids' => [$inHouse->id, $payer->id, $eligible->id], 'confirmation' => ' Confirm ',
    ])->assertInertiaFlash('guestBulkResult', ['changedIds' => [$eligible->id], 'unchangedIds' => [], 'skippedIds' => [$inHouse->id, $payer->id]])
        ->assertInertiaFlash('toast.type', 'info');

    $this->assertDatabaseHas('guests', ['id' => $eligible->id, 'full_name' => "Archived User #{$eligible->id}", 'email' => null]);
    $this->assertDatabaseHas('guests', ['id' => $inHouse->id, 'anonymized_at' => null]);
    $this->assertDatabaseHas('guests', ['id' => $payer->id, 'anonymized_at' => null]);
    expect(Activity::query()->where('event', 'guest.anonymized')->count())->toBe(1);
});

test('bulk guest requests validate the selection and confirmation before changing guests', function (string $action, array $invalid, string $field, string $message) {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->for($owner->property)->create(['is_vip' => false]);

    $this->actingAs($owner)->post(route('guests.bulk.'.$action, $owner->property->code), array_replace(['guest_ids' => [$guest->id]], $invalid))
        ->assertSessionHasErrors([$field => $message]);

    expect($guest->refresh()->is_vip)->toBeFalse()
        ->and($guest->anonymized_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'empty selection' => ['mark-vip', ['guest_ids' => []], 'guest_ids', 'The guest ids field is required.'],
    'too many guests' => ['mark-vip', ['guest_ids' => range(1, 101)], 'guest_ids', 'Select up to 100 guests at a time.'],
    'duplicate IDs' => ['mark-vip', ['guest_ids' => [1, 1]], 'guest_ids.0', 'The guest_ids.0 field has a duplicate value.'],
    'invalid ID' => ['mark-vip', ['guest_ids' => ['invalid']], 'guest_ids.0', 'The guest_ids.0 field must be an integer.'],
    'missing reason' => ['blacklist', [], 'reason', 'The reason field is required.'],
    'long reason' => ['blacklist', ['reason' => str_repeat('a', 501)], 'reason', 'The reason field must not be greater than 500 characters.'],
    'missing confirmation' => ['anonymize', [], 'confirmation', 'Type Confirm to continue.'],
    'wrong confirmation case' => ['anonymize', ['confirmation' => 'confirm'], 'confirmation', 'Type Confirm to continue.'],
]);

test('a missing guest refuses the whole batch and the route determines the operation', function () {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->for($owner->property)->create(['is_vip' => false]);
    $url = route('guests.bulk.mark-vip', $owner->property->code);

    $this->actingAs($owner)->post($url, ['guest_ids' => [$guest->id, 999999999]])->assertNotFound();
    expect($guest->refresh()->is_vip)->toBeFalse();

    $this->post($url, ['guest_ids' => [$guest->id], 'action' => 'anonymize'])
        ->assertInertiaFlash('guestBulkResult.changedIds', [$guest->id]);

    expect($guest->refresh()->is_vip)->toBeTrue()
        ->and($guest->anonymized_at)->toBeNull();
});

test('a bulk guest request cannot target another property code', function () {
    $owner = User::factory()->owner()->create();
    $guest = Guest::factory()->create();

    $this->actingAs($owner)->post(route('guests.bulk.mark-vip', $guest->property->code), ['guest_ids' => [$guest->id]])->assertNotFound();

    expect(Activity::query()->count())->toBe(0);
});
