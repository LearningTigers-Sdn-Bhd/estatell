<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Enums\NearbyPlaceStatus;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Amenity;
use App\Models\NearbyPlace;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists the code, slug, name and city of the properties the client reaches', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient();

    bookingApiGet('properties')->assertOk()->assertExactJson([
        ['code' => $property->code, 'slug' => 'sand-bay', 'name' => $property->name, 'city' => 'Semporna'],
    ]);
});

it('returns the site settings with images of current slots only', function () {
    Storage::fake(config('filesystems.public_media'));
    ['property' => $property, 'site' => $site] = bookingApiProperty();
    bookingMicrositeClient();
    $template = SiteTemplate::factory()->create(['key' => 'island-resort']);
    SiteTemplateSlot::factory()->for($template)->create(['key' => 'hero']);
    $site->update(['site_template_id' => $template->id, 'main_color' => '#0f766e', 'seo_title' => 'Sand Bay', 'seo_description' => 'A resort']);
    $site->addMedia(UploadedFile::fake()->image('hero.jpg'))->withCustomProperties(['slot' => 'hero'])->toMediaCollection('images');
    $site->addMedia(UploadedFile::fake()->image('old.jpg'))->withCustomProperties(['slot' => 'removed'])->toMediaCollection('images');

    bookingApiGet('sites/sand-bay')->assertOk()
        ->assertJsonPath('code', $property->code)
        ->assertJsonPath('template_key', 'island-resort')
        ->assertJsonPath('main_color', '#0f766e')
        ->assertJsonPath('currency', 'MYR')
        ->assertJsonPath('seo_title', 'Sand Bay')
        ->assertJsonPath('seo_description', 'A resort')
        ->assertJsonCount(1, 'images')
        ->assertJsonPath('images.0.slot', 'hero');
});

it('returns the property content with active nearby places and no review status', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient();
    $property->update(['address_line1' => 'Jalan Pantai', 'contact_phone' => '+60 12-345 6789', 'contact_email' => 'hello@example.com']);
    $property->bookingSite->update(['about_text' => 'By the sea']);
    $property->amenities()->attach(Amenity::factory()->create(['name' => 'Pool']));
    $shown = NearbyPlace::factory()->create(['name' => 'Jetty', 'status' => NearbyPlaceStatus::Pending]);
    $hidden = NearbyPlace::factory()->create(['name' => 'Hidden market']);
    $property->nearbyPlaces()->attach($shown, ['display_name' => 'The jetty', 'position' => 1, 'active' => true]);
    $property->nearbyPlaces()->attach($hidden, ['position' => 2, 'active' => false]);
    RoomRevenuePolicy::factory()->for($property)->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create(['text_en' => 'Free until 3 days']);

    $response = bookingApiGet('properties/'.$property->code)->assertOk()
        ->assertJsonPath('address.line1', 'Jalan Pantai')
        ->assertJsonPath('contact_phone', '+60 12-345 6789')
        ->assertJsonPath('contact_email', 'hello@example.com')
        ->assertJsonPath('about_text', 'By the sea')
        ->assertJsonPath('amenities.0.name', 'Pool')
        ->assertJsonCount(1, 'nearby_places')
        ->assertJsonPath('nearby_places.0.name', 'The jetty')
        ->assertJsonPath('policies.cancellation.text_en', 'Free until 3 days')
        ->assertJsonPath('policies.no_show', null)
        ->assertJsonPath('check_in_time', '15:00');

    expect($response->json('nearby_places.0'))->not->toHaveKey('status');
});

it('lists only active room types that a public plan sells', function () {
    ['property' => $property, 'roomType' => $roomType, 'dorm' => $dorm, 'plan' => $plan] = bookingApiProperty();
    bookingMicrositeClient();
    $roomType->amenities()->attach(Amenity::factory()->create(['name' => 'Balcony']));
    $archived = RoomType::factory()->for($property)->create(['archived_at' => now()]);
    $plan->roomTypes()->attach($archived);
    RoomType::factory()->for($property)->create(['name' => 'Not sold online']);

    $response = bookingApiGet('properties/'.$property->code.'/room-types')->assertOk()->assertJsonCount(2);

    expect(collect($response->json())->pluck('id')->sort()->values()->all())->toBe(collect([$roomType->id, $dorm->id])->sort()->values()->all());
    expect(collect($response->json())->firstWhere('id', $roomType->id))
        ->sell_unit->toBe('room')
        ->amenities->toBe([['name' => 'Balcony', 'category' => $roomType->amenities()->first()->category->name, 'icon' => $roomType->amenities()->first()->icon]]);
});

it('exposes no folio, guest, staff or commission fields', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient();

    $body = bookingApiGet('properties/'.$property->code)->assertOk()->getContent();

    expect($body)->not->toContain('folio')->not->toContain('commission')->not->toContain('registration_number')->not->toContain('tin');
});
