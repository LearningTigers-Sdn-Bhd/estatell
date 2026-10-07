<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\PrintedPdf;
use App\Services\Property\PrintProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
});

/** @return array{0: User, 1: Property} */
function printingProperty(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->update([
        'address_line1' => 'Lot 12, Jalan Pantai',
        'postal_code' => '88000',
        'city' => 'Kota Kinabalu',
        'country_code' => 'MY',
        'contact_phone' => '+60 88 123 456',
        'contact_email' => 'stay@example.com',
        'registration_number' => '202301012345',
        'tin' => 'C1234567890',
    ]);
    PropertySetting::factory()->for($property)->create();

    return [$owner, $property->refresh()];
}

function printSettingsUrl(Property $property, string $path = ''): string
{
    return '/p/'.$property->code.'/settings/'.$path;
}

test('the back-fill gives existing properties their print settings', function () {
    $property = Property::factory()->create();
    $property->printSetting()->delete();

    (require database_path('migrations/2026_10_07_155203_backfill_print_settings.php'))->up();

    $settings = $property->printSetting()->sole();

    expect($settings->font)->toBe(PdfFont::Standard)
        ->and($settings->font_size)->toBe(PdfFontSize::Medium)
        ->and($settings->show_logo)->toBeTrue()
        ->and($settings->show_tin)->toBeFalse()
        ->and($settings->footer_text)->toBeNull();
});

test('staff save the font, the size and the footer and the change is logged', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing'), ['font' => 'chinese', 'font_size' => 'large', 'footer_text' => '  Thank you for staying with us.  '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $property->printSetting->refresh();
    $activity = Activity::query()->where('event', 'print_settings.updated')->sole();

    expect($settings->font)->toBe(PdfFont::Chinese)
        ->and($settings->font_size)->toBe(PdfFontSize::Large)
        ->and($settings->footer_text)->toBe('Thank you for staying with us.')
        ->and($activity->properties['changed'])->toBe(['font', 'font_size', 'footer_text']);
});

test('an empty footer is stored as null and an unchanged save is not logged', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing'), ['font' => 'standard', 'font_size' => 'medium', 'footer_text' => '   '])
        ->assertSessionHasNoErrors();

    expect($property->printSetting->refresh()->footer_text)->toBeNull()
        ->and(Activity::query()->where('event', 'print_settings.updated')->count())->toBe(0);
});

test('staff save the header switches and the tax numbers', function () {
    [$owner, $property] = printingProperty();
    $sst = Tax::factory()->for($property)->create(['code' => 'SST', 'registration_number' => 'W10-1808-32000123']);

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing/header'), [
            'show_logo' => true, 'show_address' => false, 'show_contact' => true,
            'show_registration_number' => true, 'show_tin' => true, 'tax_ids' => [$sst->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $property->printSetting->refresh();
    $activity = Activity::query()->where('event', 'print_settings.updated')->sole();

    expect($settings->show_address)->toBeFalse()
        ->and($settings->show_tin)->toBeTrue()
        ->and($settings->taxes->pluck('id')->all())->toBe([$sst->id])
        ->and($activity->properties['changed'])->toBe(['show_address', 'show_tin', 'taxes']);
});

test('the header refuses a tax of another property', function () {
    [$owner, $property] = printingProperty();
    $foreign = Tax::factory()->create(['registration_number' => 'X-1']);

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing/header'), [
            'show_logo' => true, 'show_address' => false, 'show_contact' => true,
            'show_registration_number' => true, 'show_tin' => false, 'tax_ids' => [$foreign->id],
        ])
        ->assertInertiaFlash('toast.type', 'error');

    expect($property->printSetting->refresh()->show_address)->toBeTrue()
        ->and($property->printSetting->taxes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'print_settings.updated')->count())->toBe(0);
});

test('printing routes need settings.manage and the property of the user', function () {
    [$owner, $property] = printingProperty();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);
    [, $other] = printingProperty();

    $this->actingAs($frontDesk)->get(printSettingsUrl($property, 'printing/sample'))->assertForbidden();
    $this->actingAs($frontDesk)->post(printSettingsUrl($property, 'logo'), ['logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
    $this->actingAs($owner)->get(printSettingsUrl($other, 'printing/sample'))->assertNotFound();
});

test('staff upload, replace, view and remove the logo', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)->get(printSettingsUrl($property, 'logo'))->assertNotFound();

    $this->actingAs($owner)->post(printSettingsUrl($property, 'logo'), ['logo' => UploadedFile::fake()->image('first.png')])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(printSettingsUrl($property, 'logo'), ['logo' => UploadedFile::fake()->image('second.jpg')])->assertSessionHasNoErrors();

    expect($property->refresh()->getMedia(Property::LOGO))->toHaveCount(1)
        ->and($property->getFirstMedia(Property::LOGO)->file_name)->toBe('second.jpg');

    $this->actingAs($owner)->get(printSettingsUrl($property, 'logo'))->assertOk()->assertHeader('content-type', 'image/jpeg');

    $this->actingAs($owner)->delete(printSettingsUrl($property, 'logo'))->assertRedirect();

    expect($property->refresh()->getMedia(Property::LOGO))->toHaveCount(0)
        ->and(Activity::query()->where('event', 'property.updated')->count())->toBe(3);
});

test('the logo must be a PNG or JPEG up to 1 MB', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->post(printSettingsUrl($property, 'logo'), ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])
        ->assertSessionHasErrors('logo');
    $this->actingAs($owner)
        ->post(printSettingsUrl($property, 'logo'), ['logo' => UploadedFile::fake()->image('big.png')->size(1100)])
        ->assertSessionHasErrors('logo');

    expect($property->refresh()->getMedia(Property::LOGO))->toHaveCount(0);
});

test('the letterhead shows only the lines that are switched on and set', function () {
    [, $property] = printingProperty();
    $sst = Tax::factory()->for($property)->create(['code' => 'SST', 'registration_number' => 'W10-1808-32000123']);
    $removed = Tax::factory()->for($property)->create(['code' => 'TTX', 'registration_number' => null]);
    $property->printSetting->taxes()->sync([$sst->id, $removed->id]);

    $layout = app(PrintProfile::class)->for($property->refresh());

    expect($layout->propertyName)->toBe($property->name)
        ->and($layout->logoDataUri)->toBeNull()
        ->and($layout->addressLines)->toBe(['Lot 12, Jalan Pantai', '88000 Kota Kinabalu', 'Malaysia'])
        ->and($layout->contactLines)->toBe(['+60 88 123 456 · stay@example.com'])
        ->and($layout->registrationLines)->toBe(['Registration no. 202301012345', 'SST W10-1808-32000123']);

    $property->printSetting->update(['show_address' => false, 'show_contact' => false, 'show_registration_number' => false, 'show_tin' => true]);
    $layout = app(PrintProfile::class)->for($property->refresh());

    expect($layout->addressLines)->toBe([])
        ->and($layout->contactLines)->toBe([])
        ->and($layout->registrationLines)->toBe(['TIN C1234567890', 'SST W10-1808-32000123']);
});

test('the letterhead embeds the logo when it is switched on', function () {
    [, $property] = printingProperty();
    $property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);

    expect(app(PrintProfile::class)->for($property->refresh())->logoDataUri)->toStartWith('data:image/png;base64,');

    $property->printSetting->update(['show_logo' => false]);

    expect(app(PrintProfile::class)->for($property->refresh())->logoDataUri)->toBeNull();
});

test('the sample PDF prints the saved settings with the footer', function () {
    [$owner, $property] = printingProperty();
    $property->printSetting->update(['footer_text' => 'Thank you for staying with us.']);

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing/sample'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('Chinese text prints in Noto Sans SC whatever the font setting', function () {
    [, $property] = printingProperty();

    $latin = app(PrintedPdf::class)->make($property, 'pdf.print-sample', ['currency' => 'MYR'])->output();

    $property->printSetting->update(['footer_text' => '谢谢光临']);
    $chinese = app(PrintedPdf::class)->make($property->refresh(), 'pdf.print-sample', ['currency' => 'MYR'])->output();

    expect($latin)->not->toContain('NotoSansSC')
        ->and($chinese)->toContain('NotoSansSC');
});
