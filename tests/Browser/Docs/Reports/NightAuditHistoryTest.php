<?php

use App\Actions\NightAudit\RunNightAudit;
use App\Enums\AuditTrigger;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Close 14 Oct by force, the way the night audit force close page does: B1 has a blocker, so the audit needs a reason.
 * Kinabalu's earlier days were closed by the seeder, so the history then holds a forced audit with open exceptions.
 */
function historyForceCloseToday(Property $property): void
{
    $now = CarbonImmutable::parse('2026-10-15 12:00', 'UTC');

    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);

    $unit = test()->scenario('B1')->units()->where('status', UnitStatus::CheckedIn->value)->firstOrFail();
    $unit->nights()->update(['bed_id' => null, 'room_id' => null]);

    ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::Confirmed->value)->update(['late_arrival_expected' => true]);

    $result = app(RunNightAudit::class)($property, AuditTrigger::Manual, auth()->user(), true, 'The guest in the dorm moves beds tomorrow morning.');

    expect($result->isRefused())->toBeFalse();
}

test('night audit history', function () {
    historyForceCloseToday($this->property);

    // The sidebar is folded away so every column, up to Packet, fits on the 1440 px screen.
    $page = $this->open(route('reports.night-audit-history', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14']), height: 700)
        ->wait(1)
        ->click('[data-sidebar="trigger"]')
        ->wait(1);

    $this->shoot($page, 'night-audit-history',
        badges: ['Trigger', 'Forced', 'Exceptions (open / total)', 'Packet'],
        badgesFromLeft: 0,
    );
});

test('night audit packet', function () {
    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        $this->markTestSkipped('pdftoppm (poppler) is needed to turn the packet PDF into a PNG.');
    }

    historyForceCloseToday($this->property);

    $response = $this->get(route('reports.night-audit-packet', [$this->property, '2026-10-14']));
    expect($response->baseResponse->getStatusCode())->toBe(200);

    $folder = base_path('tests/Browser/Screenshots/reports');
    File::ensureDirectoryExists($folder);
    $pdf = $folder.'/night-audit-packet.pdf';
    File::put($pdf, $response->baseResponse->getContent());

    // Every page of the packet, side by side on one image.
    $pages = $folder.'/night-audit-packet-page';
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
    $sheet->writeImage($folder.'/night-audit-packet.png');

    expect(File::exists($folder.'/night-audit-packet.png'))->toBeTrue();
});
