<?php

use App\Actions\Housekeeping\AssignHousekeeper;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the export menu', function () {
    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('rooms on')
        ->click('Export')
        ->waitForText('Excel')
        ->wait(1);

    $this->shoot($page, 'print-export-menu', badges: ['PDF', 'CSV', 'Excel'], frame: '[role="menu"]');
});

test('the export button on the board', function () {
    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('rooms on');

    $this->shoot($page, 'print-export-button', badges: ['Columns', 'Export']);
});

test('export the ticked rooms', function () {
    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('rooms on')
        ->click('[aria-label="Select room 103"]')
        ->click('[aria-label="Select room 104"]')
        ->waitForText('2 selected')
        ->wait(1);

    $this->shoot($page, 'print-ticked-rooms', badges: ['2 selected']);
});

test('one housekeeper sheet', function () {
    $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();
    Room::query()->where('property_id', $this->property->id)->whereIn('number', ['102', '103', '104'])
        ->get()->each(fn (Room $room) => app(AssignHousekeeper::class)($owner, $room, $owner));

    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('rooms on')
        ->click('[aria-label="Assigned to"]')
        ->click('[role="option"]:has-text("'.$owner->name.'")')
        ->wait(1);

    $this->shoot($page, 'print-one-housekeeper', badges: [$owner->name, 'Export']);
});

test('the columns sheet', function () {
    $page = $this->open(route('housekeeping.index', $this->property))
        ->waitForText('rooms on')
        ->click('Columns')
        ->waitForText('Reset to default')
        ->wait(1);

    $this->shoot($page, 'print-columns-sheet', badges: ['Orientation', 'Columns', 'Reset to default', 'Preview PDF', 'Save'], frame: '[role="dialog"]');
});

test('the printed sheet', function () {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        $this->markTestSkipped('pdftoppm (poppler) is needed to turn the PDF into a PNG.');
    }

    $response = $this->post(route('housekeeping.pdf', $this->property), ['date' => '2026-10-14', 'sort' => 'due']);
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/housekeeping');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/print-sheet.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    exec('pdftoppm -png -r 110 -f 1 -l 1 -singlefile '.escapeshellarg($pdf).' '.escapeshellarg($folder.'/print-sheet'));
    File::delete($pdf);

    $image = new Imagick($folder.'/print-sheet.png');
    $image->cropImage($image->getImageWidth(), (int) ($image->getImageHeight() * 0.6), 0, 0);
    $image->setImagePage(0, 0, 0, 0);
    $image->writeImage($folder.'/print-sheet.png');

    expect(File::exists($folder.'/print-sheet.png'))->toBeTrue();
});
