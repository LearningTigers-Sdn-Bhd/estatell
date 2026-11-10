<?php

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitGuest;
use Spatie\Activitylog\Models\Activity;

test('staff see and directly edit a saved ID then remove and replace it without refreshing', function (bool $booking) {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $guest = Guest::factory()->for($this->property)->create(['full_name' => 'Inline ID Regression Guest', 'nationality_code' => 'MY', 'phone' => null]);
    $url = route('guests.show', [$this->property, $guest]);
    if ($booking) {
        $reservation = Reservation::factory()->for($this->property)->create(['booker_guest_id' => $guest->id]);
        $unit = ReservationUnit::factory()->for($reservation)->create([
            'room_type_id' => RoomType::factory()->for($this->property)->create()->id,
        ]);
        UnitGuest::factory()->primary()->for($guest)->create(['reservation_unit_id' => $unit->id]);
        $url = route('reservations.guests', [$this->property, $reservation]);
    }
    $page = $this->open($url);
    if ($booking) {
        $page->click('[aria-label="Show the details of Inline ID Regression Guest"]');
    }

    $page->fill('IC / Passport number', '900101141234')
        ->click($booking ? 'Save' : 'Save changes')
        ->assertValue('IC / Passport number', '900101-14-1234')
        ->click('[data-slot="toast"]:not([data-ending-style]):has-text("Inline ID Regression Guest saved.") [data-slot="toast-close"]');

    $document = $guest->documents()->sole();
    expect(Activity::query()->where('event', 'guest.document_number_viewed')->where('properties->document_id', $document->id)->exists())->toBeTrue();
    $page->assertAttributeMissing('[name="identity_number"]', 'readonly')
        ->assertAttributeMissing('[aria-label="Identity document type"]', 'disabled')
        ->fill('IC / Passport number', '900102141234')
        ->click($booking ? 'Save' : 'Save changes')
        ->assertValue('IC / Passport number', '900102-14-1234')
        ->click('[data-slot="toast"]:not([data-ending-style]):has-text("Inline ID Regression Guest saved.") [data-slot="toast-close"]');
    expect($document->refresh()->number)->toBe('900102141234');
    expect($guest->documents()->count())->toBe(1);
    $page->click('[aria-label="Remove IC number"]')
        ->assertSee('Other documents are kept.')
        ->click('Keep ID')
        ->assertValue('IC / Passport number', '900102-14-1234');
    $this->assertModelExists($document);

    $page->click('[aria-label="Remove IC number"]')->click('Remove ID')
        ->assertValue('IC / Passport number', '')
        ->assertDontSee('Other documents are kept.')
        ->click('[data-slot="toast"]:not([data-ending-style]):has-text("ID document removed.") [data-slot="toast-close"]');
    $this->assertModelMissing($document);

    $page->fill('IC / Passport number', '880101141234')
        ->click($booking ? 'Save' : 'Save changes')
        ->assertValue('IC / Passport number', '880101-14-1234')
        ->assertNoJavaScriptErrors();
})->with(['guest Details' => false, 'booking Guests' => true]);

test('removing one inline ID keeps the other document editable', function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $guest = Guest::factory()->for($this->property)->create(['nationality_code' => 'MY', 'phone' => null]);
    $mykad = GuestIdentityDocument::factory()->for($guest)->create(['type' => 'mykad', 'number' => '900101141234']);
    $passport = GuestIdentityDocument::factory()->for($guest)->create(['type' => 'passport', 'number' => 'A1234567']);

    $this->open(route('guests.show', [$this->property, $guest]))
        ->click('[aria-label="Remove IC number"]')->click('Remove ID')
        ->assertValue('IC / Passport number', 'A1234567')
        ->assertNoJavaScriptErrors();

    $this->assertModelMissing($mykad);
    $this->assertModelExists($passport);
});
