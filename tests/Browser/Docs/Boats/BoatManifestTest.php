<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->signInAsScenariosOwner();
    $this->boats = $this->enableBoats();
});

/**
 * The boats of tomorrow's arrivals (15 Oct): S3 and one of G1's Deluxe rooms on the 13:00 boat, another Deluxe room on
 * the 09:00 boat, and the third on a charter with a note.
 */
$setTomorrowsTrips = function (): void {
    $deluxe = $this->scenario('G1')->units()->where('status', 'confirmed')->orderBy('id')->get();

    $this->setBoatTrip($deluxe[0], BoatDirection::In, BoatMode::Scheduled, $this->boats['in0900']);
    $this->setBoatTrip($deluxe[1], BoatDirection::In, BoatMode::Scheduled, $this->boats['in1300']);
    $this->setBoatTrip($deluxe[2], BoatDirection::In, BoatMode::Charter, time: '15:30', note: 'Wedding party, 2 bags each');
    $this->setBoatTrip($this->scenario('S3')->units()->firstOrFail(), BoatDirection::In, BoatMode::Scheduled, $this->boats['in1300']);
};

test('the boat manifest for tomorrow', function () use ($setTomorrowsTrips) {
    $setTomorrowsTrips->call($this);

    $page = $this->open(route('reports.boat-manifest', [$this->property, 'date' => '2026-10-15', 'direction' => 'in']), height: 1100)
        ->waitForText('Charter and own boat');

    $this->shoot($page, 'boat-manifest', badges: ['Custom date', 'Boat in', '4 / 20 seats', 'Charter and own boat (front desk only)', 'Layout', 'Export']);
});

test('the boat manifest with no trips', function () {
    $page = $this->open(route('reports.boat-manifest', [$this->property, 'date' => '2026-10-14', 'direction' => 'in']))
        ->waitForText('No boat in trips on 14 Oct.');

    $this->shoot($page, 'boat-manifest-empty');
});

test('the boat manifest pdf', function () use ($setTomorrowsTrips) {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        $this->markTestSkipped('pdftoppm (poppler) is needed to turn the manifest PDF into a PNG.');
    }

    $setTomorrowsTrips->call($this);

    $response = $this->post(route('reports.pdf', [$this->property, 'boat-manifest']), ['date' => '2026-10-15', 'direction' => 'in']);
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/boats');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/boat-manifest.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    // Every page of the PDF, side by side on one image: one page for each boat.
    $pages = $folder.'/boat-manifest-page';
    exec('pdftoppm -png -r 70 '.escapeshellarg($pdf).' '.escapeshellarg($pages));
    File::delete($pdf);

    $images = new Imagick;

    foreach (collect(File::glob($pages.'-*.png'))->sort()->values() as $file) {
        $images->readImage($file);
        File::delete($file);
    }

    $images->resetIterator();
    $sheet = $images->appendImages(false);
    $sheet->setImageFormat('png');
    $sheet->writeImage($folder.'/boat-manifest-pdf.png');

    expect(File::exists($folder.'/boat-manifest-pdf.png'))->toBeTrue();
});
