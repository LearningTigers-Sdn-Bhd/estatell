<?php

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateMigratedStay;
use App\Data\Reservations\NewBookingData;
use App\Enums\BookingSourceType;
use App\Enums\OldFolioMode;
use App\Enums\SystemTransactionCode;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use Database\Factories\BookingSourceFactory;

require_once __DIR__.'/PastStaySetup.php';

/**
 * `pastStaySetup()` with the migration window open (60 days) and the Opening balance code. The property went live on
 * 2026-10-10 and trades on 2026-10-14.
 *
 * @return array<string, mixed>
 */
function migratedStaySetup(int $windowDays = 60): array
{
    $setup = pastStaySetup();
    $setup['property']->settings->forceFill(['migration_window_days' => $windowDays])->save();
    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::OpeningBalance)->create();

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 */
function createMigratedStay(array $setup, array $line = [], string $reason = 'Migrated from old PMS', OldFolioMode $mode = OldFolioMode::OneAmount, ?string $amount = '1080.00', ?string $number = 'F1203', bool $checkedOut = false): BookingResult
{
    return app(CreateMigratedStay::class)($setup['user'], $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::WalkIn),
        'units' => [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-08', 'arrivalTime' => '15:00', 'departureDate' => '2026-10-16', 'adults' => 2, ...$line]],
        'guests' => [['guestId' => $setup['guest']->id, 'unitIndex' => 0]],
        'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id]],
    ]), $reason, $mode, $amount, $number, true, $checkedOut);
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function migratedStayBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => $setup['walkIn']->id,
        'arrival_time' => '15:00',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => [], 'room_id' => $setup['rooms'][0]->id]],
        'mode' => 'migrated',
        'arrival_date' => '2026-10-08',
        'departure_date' => '2026-10-16',
        'reason' => 'Migrated from old PMS',
        'old_folio' => 'one_amount',
        'opening_amount' => '1,080.00',
        'legacy_number' => 'F1203',
        ...$overrides,
    ];
}

function nightServiceDates(): array
{
    return FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->orderBy('service_date')->get()
        ->map(fn ($line): string => $line->service_date->toDateString())->all();
}
