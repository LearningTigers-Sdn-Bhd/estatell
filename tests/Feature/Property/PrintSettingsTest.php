<?php

use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use App\Services\Property\PrintedPdf;
use App\Services\Property\PrintProfile;
use App\Services\Property\SampleDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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

    expect($settings->font)->toBe(PdfFont::Office)
        ->and($settings->font_size)->toBe(PdfFontSize::Medium)
        ->and($settings->show_logo)->toBeTrue()
        ->and($settings->show_tin)->toBeFalse()
        ->and($settings->footer_text)->toBeNull();
});

test('staff save the font, the size and the footer and the change is logged', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing'), ['font' => 'fancy', 'font_size' => 'large', 'footer_text' => '  Thank you for staying with us.  '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $property->printSetting->refresh();
    $activity = Activity::query()->where('event', 'print_settings.updated')->sole();

    expect($settings->font)->toBe(PdfFont::Fancy)
        ->and($settings->font_size)->toBe(PdfFontSize::Large)
        ->and($settings->footer_text)->toBe('Thank you for staying with us.')
        ->and($activity->properties['changed'])->toBe(['font', 'font_size', 'footer_text']);
});

test('an empty footer is stored as null and an unchanged save is not logged', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing'), ['font' => 'office', 'font_size' => 'medium', 'footer_text' => '   '])
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

test('each font style embeds its own fonts', function (PdfFont $font, array $fontNames) {
    [, $property] = printingProperty();
    $property->printSetting->update(['font' => $font]);

    $pdf = app(PrintedPdf::class)->make($property->refresh(), 'pdf.print-sample', ['currency' => 'MYR'])->output();

    foreach ($fontNames as $fontName) {
        expect($pdf)->toContain($fontName);
    }

    expect($pdf)->not->toContain('NotoSansSC');
})->with([
    'office' => [PdfFont::Office, ['Arimo']],
    'modern' => [PdfFont::Modern, ['Inter']],
    'fancy' => [PdfFont::Fancy, ['PlayfairDisplay', 'Lora']],
    'handwritten' => [PdfFont::Handwritten, ['Caveat', 'Nunito']],
]);

test('Chinese text prints in Noto Sans SC in every font style', function (PdfFont $font) {
    [, $property] = printingProperty();
    $property->printSetting->update(['font' => $font, 'footer_text' => '谢谢光临']);

    $pdf = app(PrintedPdf::class)->make($property->refresh(), 'pdf.print-sample', ['currency' => 'MYR'])->output();

    expect($pdf)->toContain('NotoSansSC');
})->with(PdfFont::cases());

test('the old fonts become the Office style', function () {
    [, $property] = printingProperty();
    $migration = require database_path('migrations/2026_10_07_161645_replace_print_settings_font_with_styles.php');

    $migration->down();
    DB::table('print_settings')->where('property_id', $property->id)->update(['font' => 'chinese']);
    $migration->up();

    expect($property->printSetting->refresh()->font)->toBe(PdfFont::Office);
});

test('the printing tabs send the settings, the profile and the taxes in the Taxes tab order', function () {
    [$owner, $property] = printingProperty();
    $off = Tax::factory()->for($property)->disabled()->create(['name' => 'Alpha levy']);
    $on = Tax::factory()->for($property)->create(['name' => 'Service tax']);

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/printing/edit')
            ->where('tab', 'general')
            ->where('settings.font', 'office')
            ->where('settings.showTin', false)
            ->where('profile.registrationNumber', '202301012345')
            ->where('profile.countryName', 'Malaysia')
            ->where('taxes.0.id', $on->id)
            ->where('taxes.1.id', $off->id)
            ->has('fonts', 4)
            ->where('fonts.2', ['value' => 'fancy', 'label' => 'Fancy', 'headingFamily' => 'Playfair Display', 'bodyFamily' => 'Lora'])
            ->where('fontSizes.2', ['value' => 'large', 'label' => 'Large', 'description' => '10.5 pt body text'])
            ->where('navigation.contexts.3.groups.0.items.2.title', 'Printing')
            ->where('navigation.contexts.3.groups.0.items.2.active', true));

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing/header'))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'header')->has('fonts', 4)->missing('fontSizes'));
});

test('the printing pages send a property without settings to the Details page', function () {
    [$owner, $property] = printingProperty();
    $property->printSetting()->delete();

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing'))
        ->assertRedirect(route('settings.edit', $property->code));
});

test('the profile tab shows the identity and the logo link', function () {
    [$owner, $property] = printingProperty();
    $logo = $property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'profile'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->where('tab', 'profile')
            ->where('profile.name', $property->name)
            ->where('profile.tin', 'C1234567890')
            ->where('profile.logoUrl', '/p/'.$property->code.'/settings/logo?v='.$logo->id));
});

test('staff save the profile and the change is logged', function () {
    [$owner, $property] = printingProperty();
    $prefix = $property->document_prefix;

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'profile'), [
            'name' => 'Sandbay Beach Resort',
            'address_line1' => 'Lot 12, Jalan Pantai',
            'address_line2' => null,
            'postal_code' => '88000',
            'city' => 'Kota Kinabalu',
            'state_code' => null,
            'contact_phone' => '+60 88 123 456',
            'contact_email' => 'hello@example.com',
            'registration_number' => '202301012345',
            'tin' => null,
        ])
        ->assertRedirect(route('settings.profile', $property->code))
        ->assertSessionHasNoErrors();

    $property->refresh();
    $activity = Activity::query()->where('event', 'property.updated')->sole();

    expect($property->name)->toBe('Sandbay Beach Resort')
        ->and($property->contact_email)->toBe('hello@example.com')
        ->and($property->tin)->toBeNull()
        ->and($property->document_prefix)->toBe($prefix)
        ->and($activity->properties['changed'])->toBe(['name', 'contact_email', 'tin']);
});

test('the profile needs a name, an address line, a city and a valid email', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'profile'), ['name' => '', 'address_line1' => '', 'city' => '', 'contact_email' => 'not-an-email'])
        ->assertSessionHasErrors(['name', 'address_line1', 'city', 'contact_email']);

    $this->actingAs(User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]))
        ->get(printSettingsUrl($property, 'profile'))
        ->assertForbidden();
});

/** @return array<string, mixed> */
function documentPayload(array $overrides = []): array
{
    return [
        'show_unit_on_lines' => true, 'nightly_breakdown' => false, 'tax_breakdown' => true,
        'show_amount_in_words' => false, 'show_cancellation_policy' => true, 'show_signature_lines' => false,
        'bank_details' => null,
        ...$overrides,
    ];
}

test('the document switches default for existing and new properties', function () {
    [, $property] = printingProperty();
    $settings = $property->printSetting->refresh();

    expect($settings->show_unit_on_lines)->toBeTrue()
        ->and($settings->nightly_breakdown)->toBeFalse()
        ->and($settings->tax_breakdown)->toBeTrue()
        ->and($settings->show_amount_in_words)->toBeFalse()
        ->and($settings->show_cancellation_policy)->toBeTrue()
        ->and($settings->show_signature_lines)->toBeFalse()
        ->and($settings->bank_details)->toBeNull();
});

test('staff save the document switches and the change logs only the changed fields', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing/documents'), documentPayload([
            'nightly_breakdown' => true, 'show_signature_lines' => true, 'bank_details' => "  Maybank 5141 2233 4455\nSandbay Resort  ",
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $property->printSetting->refresh();
    $activity = Activity::query()->where('event', 'print_settings.updated')->sole();

    expect($settings->nightly_breakdown)->toBeTrue()
        ->and($settings->show_signature_lines)->toBeTrue()
        ->and($settings->tax_breakdown)->toBeTrue()
        ->and($settings->bank_details)->toBe("Maybank 5141 2233 4455\nSandbay Resort")
        ->and($activity->properties['changed'])->toBe(['nightly_breakdown', 'show_signature_lines', 'bank_details']);
});

test('empty payment details are stored as null and an unchanged save is not logged', function () {
    [$owner, $property] = printingProperty();
    $property->printSetting->update(['bank_details' => 'Maybank 5141']);

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing/documents'), documentPayload(['bank_details' => '   ']))
        ->assertSessionHasNoErrors();

    expect($property->printSetting->refresh()->bank_details)->toBeNull();

    Activity::query()->delete();

    $this->actingAs($owner)->put(printSettingsUrl($property, 'printing/documents'), documentPayload())->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'print_settings.updated')->count())->toBe(0);
});

test('the document switches are validated', function () {
    [$owner, $property] = printingProperty();

    $this->actingAs($owner)
        ->put(printSettingsUrl($property, 'printing/documents'), ['nightly_breakdown' => 'maybe', 'bank_details' => str_repeat('a', 501)])
        ->assertSessionHasErrors(['show_unit_on_lines', 'nightly_breakdown', 'tax_breakdown', 'show_amount_in_words', 'show_cancellation_policy', 'show_signature_lines', 'bank_details']);
});

test('the documents routes need settings.manage and Basics', function () {
    [$owner, $property] = printingProperty();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $property->id]);

    $this->actingAs($frontDesk)->get(printSettingsUrl($property, 'printing/documents'))->assertForbidden();
    $this->actingAs($frontDesk)->put(printSettingsUrl($property, 'printing/documents'), documentPayload())->assertForbidden();

    $property->printSetting()->delete();

    $this->actingAs($owner)->get(printSettingsUrl($property, 'printing/documents'))->assertRedirect(route('settings.edit', $property->code));
    $this->actingAs($owner)->put(printSettingsUrl($property->refresh(), 'printing/documents'), documentPayload())->assertNotFound();
});

test('the documents tab sends the document switches', function () {
    [$owner, $property] = printingProperty();
    $property->printSetting->update(['bank_details' => 'Maybank 5141', 'show_amount_in_words' => true]);

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing/documents'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/printing/edit')
            ->where('tab', 'documents')
            ->where('settings.showUnitOnLines', true)
            ->where('settings.nightlyBreakdown', false)
            ->where('settings.showAmountInWords', true)
            ->where('settings.bankDetails', 'Maybank 5141')
            ->has('fonts', 4));
});

test('the sample booking summary is a PDF that reads the document switches', function () {
    [$owner, $property] = printingProperty();
    $property->printSetting->update(['bank_details' => 'Maybank 5141', 'show_amount_in_words' => true]);

    $this->actingAs($owner)
        ->get(printSettingsUrl($property, 'printing/sample').'?document=booking-summary')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $html = view('pdf.document-sample', [
        'print' => app(PrintProfile::class)->for($property->refresh()),
        'currency' => Currency::Myr,
        'groups' => app(SampleDocument::class)->groups($property->printSetting),
        'settings' => $property->printSetting,
    ])->render();

    expect($html)->toContain('Room, 3 nights × 250.00')
        ->and($html)->toContain('Ringgit Malaysia two thousand and four only')
        ->and($html)->toContain('Maybank 5141')
        ->and($html)->toContain('Cancellation policy');

    $property->printSetting->update(['show_cancellation_policy' => false, 'bank_details' => null, 'show_amount_in_words' => false]);
    $html = view('pdf.document-sample', [
        'print' => app(PrintProfile::class)->for($property->refresh()),
        'currency' => Currency::Myr,
        'groups' => app(SampleDocument::class)->groups($property->printSetting),
        'settings' => $property->printSetting,
    ])->render();

    expect($html)->not->toContain('Cancellation policy')
        ->not->toContain('Payment details')
        ->not->toContain('only');
});
