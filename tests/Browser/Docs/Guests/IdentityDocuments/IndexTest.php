<?php

use App\Actions\Guests\AddIdentityDocument;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use Illuminate\Http\UploadedFile;

/**
 * The card reader bridge is a program on the front-desk PC, so a test must not depend on it. The scanner always shows
 * "Bridge offline" here.
 * The stub is wrapped so the script returns nothing: the browser driver calls a returned function.
 */
const IDENTITY_BRIDGE_OFFLINE = "(() => { const real = window.fetch.bind(window); window.fetch = (url, ...rest) => String(url).includes('127.0.0.1:9292') ? Promise.reject(new TypeError('Failed to fetch')) : real(url, ...rest); })();";

/**
 * A plain card-shaped scan, so the screenshots do not show the black image of a fake upload.
 */
function identityScanImage(string $name, bool $portrait): UploadedFile
{
    $image = imagecreatetruecolor(600, 380);
    imagefill($image, 0, 0, imagecolorallocate($image, 226, 232, 240));
    $ink = imagecolorallocate($image, 100, 116, 139);

    if ($portrait) {
        imagefilledrectangle($image, 30, 60, 190, 280, imagecolorallocate($image, 148, 163, 184));
        imagefilledellipse($image, 110, 130, 80, 80, imagecolorallocate($image, 226, 232, 240));
        imagefilledellipse($image, 110, 260, 140, 120, imagecolorallocate($image, 226, 232, 240));
    }

    foreach ([70, 110, 150, 190, 230, 270] as $y) {
        imagefilledrectangle($image, $portrait ? 220 : 40, $y, $portrait ? 560 : 560, $y + 14, $ink);
    }

    $path = sys_get_temp_dir().'/'.$name;
    imagejpeg($image, $path, 80);

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');

    $this->guest = Guest::factory()->create([
        'property_id' => $this->property->id,
        'full_name' => 'Aisyah binti Rahman',
        'email' => 'aisyah.rahman@example.my',
        'phone' => '+60123456789',
    ]);

    $add = app(AddIdentityDocument::class);
    $actor = auth()->user();
    $add($actor, $this->guest, new IdentityDocumentInputData(IdentityDocumentType::MyKad, '900101141234', 'MY', '2031-03-15'), identityScanImage('front.jpg', true), identityScanImage('back.jpg', false));
    $add($actor, $this->guest, new IdentityDocumentInputData(IdentityDocumentType::Passport, 'A12345678', 'MY', '2025-06-01'));
});

test('documents tab', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('ID documents');

    $this->shoot($page, 'documents-tab', badges: ['Add document', 'Reveal', 'Front scan', 'Edit', 'Remove']);
});

test('revealed number', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('ID documents')
        ->click('[aria-label="Reveal MyKad number"]')
        ->waitForText('900101-14-1234');

    $this->shoot($page, 'revealed-number');
});

test('add document sheet', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('ID documents');
    $page->script(IDENTITY_BRIDGE_OFFLINE);
    $page->click('Add document')
        ->waitForText('Add ID document')
        ->type('#document-number', '850505-10-5678')
        ->wait(1);

    $this->shoot($page, 'add-document-sheet', frame: '[role="dialog"]');
});

test('edit document sheet', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('ID documents');
    $page->script(IDENTITY_BRIDGE_OFFLINE);
    $page->click('Edit')
        ->waitForText('Save document')
        ->wait(1);

    $this->shoot($page, 'edit-document-sheet', frame: '[role="dialog"]');
});

test('remove document dialog', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('ID documents')
        ->click('Remove')
        ->waitForText('This cannot be undone.');

    $this->shoot($page, 'remove-document-dialog', frame: '[role="alertdialog"]');
});
