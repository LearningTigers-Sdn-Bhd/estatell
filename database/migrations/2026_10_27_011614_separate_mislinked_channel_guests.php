<?php

use App\Services\Guests\GuestName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Separate reservations that were mistakenly merged into one guest profile because of shared OTA support contacts
     * (e.g. lodgingsupport@trip.com, +601546000327). Clear OTA support emails/phones from guests.
     */
    public function up(): void
    {
        $guestName = app(GuestName::class);

        // 1. Clear known OTA support emails and call center hotlines from guests
        DB::table('guests')
            ->where(function ($query) {
                $query->where('email', 'like', '%@trip.com')
                    ->orWhere('email', 'like', '%@ctrip.com')
                    ->orWhere('phone', '+601546000327')
                    ->orWhere('phone', '+442039293737')
                    ->orWhere('phone', '+862152296241');
            })
            ->update([
                'email' => null,
                'phone' => null,
                'updated_at' => now(),
            ]);

        // 2. Separate mislinked channel reservations where the reservation's booker name differs
        // from the OTA revision customer name
        $revisions = DB::table('channel_booking_revisions')
            ->join('reservations', 'channel_booking_revisions.reservation_id', '=', 'reservations.id')
            ->join('guests', 'reservations.booker_guest_id', '=', 'guests.id')
            ->whereNotNull('channel_booking_revisions.reservation_id')
            ->whereNotNull('channel_booking_revisions.processed_at')
            ->select(
                'channel_booking_revisions.id as revision_id',
                'channel_booking_revisions.payload',
                'reservations.id as reservation_id',
                'reservations.property_id',
                'reservations.booker_guest_id',
                'guests.full_name as current_guest_name',
            )
            ->get();

        foreach ($revisions as $rev) {
            $payload = json_decode((string) $rev->payload, true);
            $customer = $payload['customer'] ?? [];
            $otaName = trim(trim((string) ($customer['name'] ?? '')).' '.trim((string) ($customer['surname'] ?? '')));

            if ($otaName === '' || $otaName === $rev->current_guest_name) {
                continue;
            }

            // Check if another reservation shares this booker_guest_id
            $otherReservationsCount = DB::table('reservations')
                ->where('booker_guest_id', $rev->booker_guest_id)
                ->where('id', '!=', $rev->reservation_id)
                ->count();

            if ($otherReservationsCount === 0) {
                // If this is the only reservation for this guest, update the guest's name to the OTA name
                DB::table('guests')->where('id', $rev->booker_guest_id)->update([
                    'full_name' => $otaName,
                    'name_key' => $guestName->key($otaName),
                    'updated_at' => now(),
                ]);
            } else {
                // Create a distinct guest for this reservation and re-point the reservation
                $newGuestId = DB::table('guests')->insertGetId([
                    'property_id' => $rev->property_id,
                    'full_name' => $otaName,
                    'name_key' => $guestName->key($otaName),
                    'email' => null,
                    'phone' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('reservations')->where('id', $rev->reservation_id)->update([
                    'booker_guest_id' => $newGuestId,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // One-way data fix
    }
};
